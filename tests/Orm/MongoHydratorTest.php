<?php

namespace Tests\Pronchev\Pinecore\Orm;

use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Orm\MongoHydrator;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedAddress;
use Tests\Pronchev\Pinecore\Fixtures\Orm\OptionalFieldsUser;
use Tests\Pronchev\Pinecore\Fixtures\Orm\SimpleUser;
use Tests\Pronchev\Pinecore\Fixtures\Orm\UserWithEmbedded;
use Tests\Pronchev\Pinecore\Fixtures\Orm\UserWithEmbeddedList;

final class MongoHydratorTest extends TestCase
{
    private MongoHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new MongoHydrator();
    }

    #[Test]
    public function simpleEntityRoundTrip(): void
    {
        $user = new SimpleUser(id: 'u1', email: 'a@b.c', displayName: 'Alice');

        $doc = $this->hydrator->dehydrate($user);

        // #[Field(name: 'display_name')] must be honoured on dehydrate
        self::assertSame(
            ['id' => 'u1', 'email' => 'a@b.c', 'display_name' => 'Alice'],
            $doc,
        );

        $hydrated = $this->hydrator->hydrate(SimpleUser::class, $doc);
        self::assertEquals($user, $hydrated);
    }

    #[Test]
    public function embeddedRoundTripUsesRenamedFieldKey(): void
    {
        $user = new UserWithEmbedded(
            id: 'u1',
            email: 'a@b.c',
            address: new EmbeddedAddress(streetName: 'Baker St', city: 'London'),
        );

        $doc = $this->hydrator->dehydrate($user);

        // #[Field(name: 'street_name')] on the embedded class must be honoured —
        // dehydrate must NOT write the property name 'streetName' as the key.
        self::assertSame(
            ['street_name' => 'Baker St', 'city' => 'London'],
            $doc['address'],
            'dehydrate must use #[Field(name: ...)] for embedded properties',
        );

        $hydrated = $this->hydrator->hydrate(UserWithEmbedded::class, $doc);
        self::assertEquals($user, $hydrated);
    }

    #[Test]
    public function embeddedListRoundTrip(): void
    {
        $user = new UserWithEmbeddedList(
            id: 'u1',
            email: 'a@b.c',
            addresses: [
                new EmbeddedAddress(streetName: 'Baker St', city: 'London'),
                new EmbeddedAddress(streetName: 'Main Rd',  city: 'Boston'),
            ],
        );

        $doc = $this->hydrator->dehydrate($user);

        self::assertCount(2, $doc['addresses']);
        self::assertSame('Baker St', $doc['addresses'][0]['street_name']);
        self::assertSame('Boston',   $doc['addresses'][1]['city']);

        $hydrated = $this->hydrator->hydrate(UserWithEmbeddedList::class, $doc);
        self::assertEquals($user, $hydrated);
    }

    #[Test]
    public function hydrateAcceptsBSONDocument(): void
    {
        $bson = new BSONDocument([
            'id'           => 'u1',
            'email'        => 'a@b.c',
            'display_name' => 'Alice',
        ]);

        $hydrated = $this->hydrator->hydrate(SimpleUser::class, $bson);

        self::assertSame('u1',     $hydrated->id);
        self::assertSame('a@b.c',  $hydrated->email);
        self::assertSame('Alice',  $hydrated->displayName);
    }

    #[Test]
    public function hydrateAcceptsBSONArrayForEmbeddedList(): void
    {
        $doc = new BSONDocument([
            'id'        => 'u1',
            'email'     => 'a@b.c',
            'addresses' => new BSONArray([
                new BSONDocument(['street_name' => 'Baker St', 'city' => 'London']),
            ]),
        ]);

        $hydrated = $this->hydrator->hydrate(UserWithEmbeddedList::class, $doc);

        self::assertCount(1, $hydrated->addresses);
        self::assertSame('Baker St', $hydrated->addresses[0]->streetName);
        self::assertSame('London',   $hydrated->addresses[0]->city);
    }

    #[Test]
    public function missingFieldFallsBackToConstructorDefault(): void
    {
        // 'name' is omitted from the document → the constructor default 'guest' must kick in.
        $doc = ['id' => 'u1', 'email' => 'a@b.c', 'note' => null];

        $hydrated = $this->hydrator->hydrate(OptionalFieldsUser::class, $doc);

        self::assertSame('guest', $hydrated->name);
    }

    #[Test]
    public function missingNullableFieldBecomesNull(): void
    {
        // 'note' (?string, no default) is omitted → must be null.
        $doc = ['id' => 'u1', 'email' => 'a@b.c', 'name' => 'Alice'];

        $hydrated = $this->hydrator->hydrate(OptionalFieldsUser::class, $doc);

        self::assertNull($hydrated->note);
        self::assertSame('Alice', $hydrated->name);
    }

    #[Test]
    public function missingRequiredFieldThrowsLogicException(): void
    {
        // 'email' is required (non-nullable, no default) → expect a clear, actionable error,
        // not a TypeError leaking from the constructor.
        $doc = ['id' => 'u1', 'note' => null, 'name' => 'Alice'];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/email/');

        $this->hydrator->hydrate(OptionalFieldsUser::class, $doc);
    }
}

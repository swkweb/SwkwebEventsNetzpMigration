<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

/**
 * Rows to write into the SwkwebEvents tables, built by {@see ImportPlanner} and written by {@see TargetWriter}. All IDs
 * are hex; dates are storage strings in UTC.
 *
 * @phpstan-import-type FieldSet from FieldSetBuilder
 * @phpstan-import-type SnapshotEntry from AnswerMapper
 *
 * @phpstan-type Category array{id: string, createdAt: string, updatedAt: ?string, translations: array<string, array{name: ?string, teaser: ?string}>}
 * @phpstan-type Event array{id: string, productId: ?string, mediaId: ?string, categoryId: ?string, fieldSetId: ?string, timezone: string, bookable: bool, archived: bool, collectAttendeeNames: bool, createdAt: string, updatedAt: ?string, translations: array<string, array{title: ?string, teaser: ?string, description: ?string, url: ?string}>, tagIds: list<string>}
 * @phpstan-type Slot array{id: string, eventId: string, startAt: string, endAt: string, active: bool, capacity: int, netzpBooked: int, createdAt: string, updatedAt: ?string, translations: array<string, array{location: ?string, address: ?string}>}
 * @phpstan-type Participant array{id: string, slotId: string, orderId: ?string, orderLineItemId: ?string, position: int, status: string, cancellationSource: ?string, statusBeforeCancellation: ?string, name: ?string, comment: ?string, answers: list<SnapshotEntry>, ticketNumber: ?string, scanCode: string, createdAt: string, updatedAt: ?string}
 */
final class ImportPlan
{
    /**
     * @var array<string, Category>
     */
    public array $categories = [];

    /**
     * @var array<string, FieldSet>
     */
    public array $fieldSets = [];

    /**
     * @var array<string, Event>
     */
    public array $events = [];

    /**
     * @var array<string, Slot>
     */
    public array $slots = [];

    /**
     * @var array<string, Participant>
     */
    public array $participants = [];

    /**
     * @var array<string, true> IDs of participants rebuilt from an order because their row is missing
     */
    public array $reconstructed = [];

    /**
     * @var array<string, array<string, mixed>> `payload.swkwebEvent` by order line item ID
     */
    public array $lineItemSnapshots = [];
}

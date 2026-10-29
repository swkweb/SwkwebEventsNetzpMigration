<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

use Shopware\Core\Defaults;
use Swkweb\EventsNetzpMigration\Netzp\NetzpReader;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Reads NetzpEvents6's data and converts it into an {@see ImportPlan}. Used by the preflight check and by the import,
 * so both report the same findings; nothing is written here.
 *
 * @phpstan-import-type Event from NetzpReader
 * @phpstan-import-type Participant from NetzpReader as SourceParticipant
 * @phpstan-import-type Answer from NetzpReader
 * @phpstan-import-type MappingEntry from FieldSetBuilder
 *
 * @phpstan-type PayloadSeat array{participantId: string, lineItemId: string, orderId: string, slotId: ?string, position: int, name: ?string, answers: list<Answer>}
 */
class ImportPlanner
{
    final public const STATUS_MAP = ['0' => 'new', '1' => 'booked', '2' => 'confirmed', '3' => 'checked_in', '9' => 'cancelled'];

    private const CANCELLATION_SOURCE_UNKNOWN = 'unknown';
    private const ORDER_STATE_CANCELLED = 'cancelled';
    private const SNAPSHOT_VERSION = 1;

    public function __construct(
        private readonly NetzpReader $reader,
    ) {}

    /**
     * @param array<string, MappingEntry> $mapping confirmed field mappings by event ID
     */
    public function plan(MigrationReport $report, array $mapping = []): ImportPlan
    {
        $plan = new ImportPlan();
        if (!$this->reader->inspectSchema($report)) {
            return $plan;
        }

        foreach ($this->reader->getTicketDrafts() as $draft) {
            $report->block('order_draft', 'An order with tickets has an open draft version. Finish or discard the order edit.', $draft);
        }

        $languages = $this->reader->getLanguages();
        $events = $this->reader->getEvents();

        $fieldSets = new FieldSetBuilder(Defaults::LANGUAGE_SYSTEM, $languages, $mapping);
        $fieldSets->build($events, $report);
        $plan->fieldSets = $fieldSets->getFieldSets();
        $report->setSection('fieldMapping', array_values($fieldSets->getMappingEntries()));

        $this->planCategories($plan, $report);
        $this->planEvents($plan, $events, $fieldSets, $this->getCollectAttendeeNames($report), $report);
        $this->planSlots($plan, $report);

        $seats = $this->indexPayloadSeats($plan, $report);
        $this->planParticipants($plan, $seats, $fieldSets, $report);
        $this->compareCounts($plan, $report);
        $this->countLanguages($events, $languages, $report);

        return $plan;
    }

    private function planCategories(ImportPlan $plan, MigrationReport $report): void
    {
        foreach ($this->reader->getCategories() as $category) {
            $plan->categories[$category['id']] = $category;
        }

        $report->setCount('categories', \count($plan->categories));
    }

    /**
     * @param array<string, Event> $events
     */
    private function planEvents(ImportPlan $plan, array $events, FieldSetBuilder $fieldSets, bool $collectAttendeeNames, MigrationReport $report): void
    {
        $products = $this->reader->getExistingIds('product', array_values(array_filter(array_column($events, 'productId'))), true);
        $media = $this->reader->getExistingIds('media', array_values(array_filter(array_column($events, 'mediaId'))));
        $eventTags = $this->reader->getEventTags();
        $tags = $this->reader->getExistingIds('tag', array_merge([], ...array_values($eventTags)));

        $linkedProducts = [];
        foreach ($events as $event) {
            $productId = $event['productId'];
            if ($productId !== null && !isset($products[$productId])) {
                $report->warn('event_product_missing', 'The product of an event no longer exists; the event is imported without product.', ['eventId' => $event['id'], 'productId' => $productId]);
                $productId = null;
            }

            if ($productId !== null && isset($linkedProducts[$productId])) {
                $report->warn('event_product_shared', 'Two events use the same product; SwkwebEvents allows one event per product, so the later event is imported without product.', ['eventId' => $event['id'], 'otherEventId' => $linkedProducts[$productId], 'productId' => $productId]);
                $productId = null;
            }

            if ($productId !== null) {
                $linkedProducts[$productId] = $event['id'];
            }

            if ($event['invalidTimezone'] !== null) {
                $report->warn('event_timezone_invalid', 'The timezone of an event is unknown; the event uses UTC.', ['eventId' => $event['id'], 'timezone' => $event['invalidTimezone']]);
            }

            $mediaId = $event['mediaId'];
            if ($mediaId !== null && !isset($media[$mediaId])) {
                $report->warn('event_media_missing', 'The image of an event no longer exists.', ['eventId' => $event['id'], 'mediaId' => $mediaId]);
                $mediaId = null;
            }

            $categoryId = $event['categoryId'];
            if ($categoryId !== null && !isset($plan->categories[$categoryId])) {
                $report->warn('event_category_missing', 'The category of an event no longer exists.', ['eventId' => $event['id'], 'categoryId' => $categoryId]);
                $categoryId = null;
            }

            $tagIds = [];
            foreach ($eventTags[$event['id']] ?? [] as $tagId) {
                if (isset($tags[$tagId])) {
                    $tagIds[] = $tagId;
                } else {
                    $report->warn('event_tag_missing', 'A tag of an event no longer exists and is skipped.', ['eventId' => $event['id'], 'tagId' => $tagId]);
                }
            }

            $plan->events[$event['id']] = [
                'id' => $event['id'],
                'productId' => $productId,
                'mediaId' => $mediaId,
                'categoryId' => $categoryId,
                'fieldSetId' => $fieldSets->getFieldSetId($event['id']),
                'timezone' => $event['timezone'],
                'bookable' => $event['bookable'],
                'archived' => $event['archived'],
                'collectAttendeeNames' => $collectAttendeeNames,
                'createdAt' => $event['createdAt'],
                'updatedAt' => $event['updatedAt'],
                'translations' => array_map(static fn (array $translation): array => [
                    'title' => $translation['title'],
                    'teaser' => $translation['teaser'],
                    'description' => $translation['description'],
                    'url' => $translation['url'],
                ], $event['translations']),
                'tagIds' => $tagIds,
            ];
        }

        $report->setCount('events', \count($plan->events));
    }

    private function planSlots(ImportPlan $plan, MigrationReport $report): void
    {
        foreach ($this->reader->getSlots() as $slot) {
            if (!isset($plan->events[$slot['eventId']])) {
                $report->warn('slot_without_event', 'A date belongs to no existing event and is skipped.', ['slotId' => $slot['id']]);

                continue;
            }

            if ($slot['from'] === null) {
                $report->block('slot_without_start', 'A date has no start time.', ['slotId' => $slot['id'], 'eventId' => $slot['eventId']]);

                continue;
            }

            $until = $slot['until'];
            if ($until === null || $until < $slot['from']) {
                $report->warn('slot_without_end', 'A date has no valid end time; its start is used as end.', ['slotId' => $slot['id']]);
                $until = $slot['from'];
            }

            $plan->slots[$slot['id']] = [
                'id' => $slot['id'],
                'eventId' => $slot['eventId'],
                'startAt' => $slot['from'],
                'endAt' => $until,
                'active' => $slot['active'],
                'capacity' => $slot['capacity'],
                'netzpBooked' => $slot['booked'],
                'createdAt' => $slot['createdAt'],
                'updatedAt' => $slot['updatedAt'],
                'translations' => $slot['translations'],
            ];
        }

        $report->setCount('slots', \count($plan->slots));
    }

    /**
     * Indexes the seats of all ticket line items by participant ID and builds the line item snapshots.
     *
     * @return array<string, PayloadSeat>
     */
    private function indexPayloadSeats(ImportPlan $plan, MigrationReport $report): array
    {
        $seats = [];
        $lineItems = 0;

        foreach ($this->reader->getTicketLineItems() as $lineItem) {
            $lineItems++;
            $payload = \is_array($lineItem['payload']['netzp_event'] ?? null) ? $lineItem['payload']['netzp_event'] : [];
            $slotId = \is_string($payload['id'] ?? null) ? strtolower($payload['id']) : null;
            $ids = \is_array($payload['participantsIds'] ?? null) ? $payload['participantsIds'] : [];
            $names = \is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
            $answers = \is_array($payload['additionalFields'] ?? null) ? $payload['additionalFields'] : [];

            if ($ids !== [] && \count($ids) !== $lineItem['quantity']) {
                $report->warn('payload_quantity_mismatch', 'The seats in a ticket line item do not match its quantity.', ['lineItemId' => $lineItem['id'], 'quantity' => $lineItem['quantity'], 'seats' => \count($ids)]);
            }

            $position = 0;
            foreach ($ids as $index => $participantId) {
                if (!\is_string($participantId) || $participantId === '') {
                    continue;
                }

                $participantId = strtolower($participantId);
                $seats[$participantId] = [
                    'participantId' => $participantId,
                    'lineItemId' => $lineItem['id'],
                    'orderId' => $lineItem['orderId'],
                    'slotId' => $slotId,
                    'position' => ++$position,
                    'name' => \is_scalar($names[$index] ?? null) ? (string) $names[$index] : null,
                    'answers' => \is_array($answers[$index] ?? null) ? NetzpReader::normalizeAnswers($answers[$index]) : [],
                ];
            }

            $plan->lineItemSnapshots[$lineItem['id']] = ['slotId' => $slotId, 'languageId' => $lineItem['languageId'], 'payload' => $payload];
        }

        $report->setCount('ticketLineItems', $lineItems);

        return $seats;
    }

    /**
     * @param array<string, PayloadSeat> $seats
     */
    private function planParticipants(ImportPlan $plan, array $seats, FieldSetBuilder $fieldSets, MigrationReport $report): void
    {
        $source = $this->reader->getParticipants();
        $orderIds = [...array_column($seats, 'orderId'), ...array_values(array_filter(array_column($source, 'orderId')))];
        $orderStates = $this->reader->getOrderStates($orderIds);

        // Line items per order and slot, for participants that only carry an order ID.
        $lineItemsBySlot = [];
        foreach ($seats as $seat) {
            if ($seat['slotId'] !== null) {
                $lineItemsBySlot[$seat['orderId']][$seat['slotId']][$seat['lineItemId']] = true;
            }
        }

        $positions = [];
        foreach ($seats as $seat) {
            $positions[$seat['lineItemId']] = max($positions[$seat['lineItemId']] ?? 0, $seat['position']);
        }

        foreach ($source as $participant) {
            $report->count('participants.status.' . ($participant['status'] === '' ? 'empty' : $participant['status']));
            $status = self::STATUS_MAP[$participant['status']] ?? null;
            if ($status === null) {
                $report->block('participant_status_unknown', 'A participant has an unknown status.', ['participantId' => $participant['id'], 'status' => $participant['status']]);

                continue;
            }

            $slot = $plan->slots[$participant['slotId']] ?? null;
            if ($slot === null) {
                $report->warn('participant_slot_missing', 'A participant belongs to a date that no longer exists and is not imported.', ['participantId' => $participant['id'], 'slotId' => $participant['slotId']]);

                continue;
            }

            if ($participant['ticketNumber'] === null) {
                $report->count('participants.withoutTicketNumber');
            }

            $seat = $seats[$participant['id']] ?? null;
            $orderId = $seat['orderId'] ?? null;
            $lineItemId = $seat['lineItemId'] ?? null;
            $position = $seat['position'] ?? 1;

            if ($seat !== null && $seat['slotId'] !== null && $seat['slotId'] !== $participant['slotId']) {
                $report->warn('participant_slot_differs', 'A participant belongs to another date than its order line item.', ['participantId' => $participant['id']]);
            }

            if ($seat === null && $participant['orderId'] !== null) {
                $candidates = array_keys($lineItemsBySlot[$participant['orderId']][$participant['slotId']] ?? []);
                if (\count($candidates) === 1) {
                    $orderId = $participant['orderId'];
                    $lineItemId = $candidates[0];
                    $position = $positions[$lineItemId] = ($positions[$lineItemId] ?? 0) + 1;
                    $report->count('participants.linkedByOrder');
                } else {
                    $report->warn('participant_order_unresolved', 'A participant references an order without a unique ticket line item for its date; it is imported as manual seat.', ['participantId' => $participant['id'], 'orderId' => $participant['orderId'], 'candidates' => \count($candidates)]);
                }
            }

            $plan->participants[$participant['id']] = $this->createParticipant(
                $participant['id'],
                $slot['id'],
                $orderId,
                $lineItemId,
                $position,
                $status,
                $orderId !== null ? ($orderStates[$orderId] ?? null) : null,
                $participant['name'],
                $participant['comment'],
                $fieldSets->getFields($slot['eventId']),
                $participant['answers'],
                $participant['ticketNumber'],
                $participant['createdAt'],
                $participant['updatedAt'],
                $report,
            );

            if ($participant['legacyAnswers']) {
                $report->warn('legacy_answers', 'Answers converted from the legacy format.', ['participantId' => $participant['id']]);
            }

            $report->count($orderId === null ? 'participants.manual' : 'participants.ordered');
        }

        $this->reconstructMissing($plan, $seats, $source, $orderStates, $fieldSets, $report);
        $this->buildSnapshots($plan, $seats, $fieldSets, $report);

        $report->setCount('participants', \count($plan->participants));
    }

    /**
     * Seats that appear in an order but have no participant row, because NetzpEvents6 skipped them (archived or not
     * bookable event at checkout) or they were deleted. Deleting a date also deletes its participants in NetzpEvents6;
     * such seats cannot exist in SwkwebEvents, their order keeps the event data of its snapshot.
     *
     * @param array<string, PayloadSeat> $seats
     * @param array<string, SourceParticipant> $source
     * @param array<string, string> $orderStates
     */
    private function reconstructMissing(ImportPlan $plan, array $seats, array $source, array $orderStates, FieldSetBuilder $fieldSets, MigrationReport $report): void
    {
        foreach ($seats as $participantId => $seat) {
            if (isset($source[$participantId])) {
                continue;
            }

            $slot = $seat['slotId'] === null ? null : ($plan->slots[$seat['slotId']] ?? null);
            if ($slot === null) {
                $report->warn('seat_of_deleted_date', 'A ticket of an order belongs to a date that no longer exists. The order keeps its event data, but has no seat and its stored ticket PDF can no longer be downloaded.', ['participantId' => $participantId, 'lineItemId' => $seat['lineItemId']]);
                $report->count('participants.ofDeletedDates');

                continue;
            }

            if (!isset($orderStates[$seat['orderId']])) {
                $report->block('payload_seat_unresolved', 'An order contains a ticket without participant, and its order cannot be resolved.', ['participantId' => $participantId, 'lineItemId' => $seat['lineItemId']]);

                continue;
            }

            $plan->participants[$participantId] = $this->createParticipant(
                $participantId,
                $slot['id'],
                $seat['orderId'],
                $seat['lineItemId'],
                $seat['position'],
                'booked',
                $orderStates[$seat['orderId']],
                $seat['name'],
                null,
                $fieldSets->getFields($slot['eventId']),
                $seat['answers'],
                null,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                null,
                $report,
            );
            $plan->reconstructed[$participantId] = true;
            $report->warn('participant_reconstructed', 'A ticket of an order had no participant row and is reconstructed from the order.', ['participantId' => $participantId, 'lineItemId' => $seat['lineItemId']]);
            $report->count('participants.reconstructed');
        }
    }

    /**
     * @param list<array{id: string, name: string, position: int, labels: array<string, string>}> $fields
     * @param list<Answer> $answers
     *
     * @return array{id: string, slotId: string, orderId: ?string, orderLineItemId: ?string, position: int, status: string, cancellationSource: ?string, statusBeforeCancellation: ?string, name: ?string, comment: ?string, answers: list<array{fieldId: ?string, name: string, type: string, label: string, value: ?string, printableValue: string}>, ticketNumber: ?string, scanCode: string, createdAt: string, updatedAt: ?string}
     */
    private function createParticipant(
        string $id,
        string $slotId,
        ?string $orderId,
        ?string $lineItemId,
        int $position,
        string $status,
        ?string $orderState,
        ?string $name,
        ?string $comment,
        array $fields,
        array $answers,
        ?string $ticketNumber,
        string $createdAt,
        ?string $updatedAt,
        MigrationReport $report,
    ): array {
        $cancellationSource = null;
        $statusBeforeCancellation = null;

        if ($status === 'cancelled') {
            // NetzpEvents6 stores no cancellation source: reopening an order must not reactivate these seats.
            $cancellationSource = self::CANCELLATION_SOURCE_UNKNOWN;
            if ($orderState === self::ORDER_STATE_CANCELLED) {
                $report->warn('cancelled_seat_on_cancelled_order', 'A cancelled seat on a cancelled order; reopening the order does not reactivate it.', ['participantId' => $id]);
            }
        } elseif ($orderState === self::ORDER_STATE_CANCELLED) {
            $report->warn('active_seat_on_cancelled_order', 'An active seat on a cancelled order is imported as cancelled.', ['participantId' => $id, 'status' => $status]);
            $statusBeforeCancellation = $status;
            $status = 'cancelled';
            $cancellationSource = self::CANCELLATION_SOURCE_UNKNOWN;
        }

        if ($name !== null && preg_match('/^\(\d+\)$/', trim($name)) === 1) {
            $report->warn('anonymous_placeholder', 'An anonymous placeholder name is imported as empty name.', ['participantId' => $id, 'name' => $name]);
            $name = null;
        }

        $mapped = AnswerMapper::map($fields, $answers, Defaults::LANGUAGE_SYSTEM);
        foreach ($mapped['unmatched'] as $answer) {
            $report->warn('answer_unmatched', 'An answer matches no field of the event and is kept with its own label.', ['participantId' => $id, 'answer' => $answer]);
        }

        return [
            'id' => $id,
            'slotId' => $slotId,
            'orderId' => $orderId,
            'orderLineItemId' => $lineItemId,
            'position' => $position,
            'status' => $status,
            'cancellationSource' => $cancellationSource,
            'statusBeforeCancellation' => $statusBeforeCancellation,
            'name' => $name === null || trim($name) === '' ? null : trim($name),
            'comment' => $comment,
            'answers' => $mapped['snapshot'],
            'ticketNumber' => $ticketNumber,
            // NetzpEvents6's ticket QR codes contain the participant ID.
            'scanCode' => $id,
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
        ];
    }

    /**
     * Replaces the raw line item data with SwkwebEvents' snapshot (`payload.swkwebEvent`).
     *
     * @param array<string, PayloadSeat> $seats
     */
    private function buildSnapshots(ImportPlan $plan, array $seats, FieldSetBuilder $fieldSets, MigrationReport $report): void
    {
        $seatsByLineItem = [];
        foreach ($seats as $seat) {
            $seatsByLineItem[$seat['lineItemId']][$seat['position']] = $seat;
        }

        foreach ($plan->lineItemSnapshots as $lineItemId => $data) {
            $slotId = \is_string($data['slotId'] ?? null) ? $data['slotId'] : null;
            $payload = \is_array($data['payload'] ?? null) ? $data['payload'] : [];
            $slot = $slotId === null ? null : ($plan->slots[$slotId] ?? null);
            $event = $slot === null ? null : ($plan->events[$slot['eventId']] ?? null);

            $lineSeats = $seatsByLineItem[$lineItemId] ?? [];
            ksort($lineSeats);

            if ($slot === null || $event === null) {
                $snapshot = $this->createDeletedDateSnapshot($slotId, $payload, $lineSeats);
                if ($snapshot === null) {
                    $report->warn('line_item_without_snapshot', 'A ticket line item has neither a date nor usable event data; it keeps its NetzpEvents6 payload only.', ['lineItemId' => $lineItemId]);
                    unset($plan->lineItemSnapshots[$lineItemId]);
                } else {
                    $plan->lineItemSnapshots[$lineItemId] = $snapshot;
                }

                continue;
            }

            $fields = $fieldSets->getFields($event['id']);
            $languageId = \is_string($data['languageId'] ?? null) ? $data['languageId'] : Defaults::LANGUAGE_SYSTEM;

            $plan->lineItemSnapshots[$lineItemId] = [
                'version' => self::SNAPSHOT_VERSION,
                'eventId' => $event['id'],
                'slotId' => $slot['id'],
                'title' => \is_string($payload['title'] ?? null) ? $payload['title'] : ($event['translations'][Defaults::LANGUAGE_SYSTEM]['title'] ?? null),
                'teaser' => $event['translations'][$languageId]['teaser'] ?? $event['translations'][Defaults::LANGUAGE_SYSTEM]['teaser'] ?? null,
                'startAt' => self::toAtom($slot['startAt']),
                'endAt' => self::toAtom($slot['endAt']),
                'timezone' => $event['timezone'],
                'collectAttendeeNames' => $event['collectAttendeeNames'],
                'location' => \is_string($payload['location'] ?? null) ? $payload['location'] : null,
                'address' => \is_string($payload['address'] ?? null) ? $payload['address'] : null,
                'attendees' => array_values(array_map(static fn (array $seat): array => [
                    'name' => $plan->participants[$seat['participantId']]['name'] ?? (preg_match('/^\(\d+\)$/', trim((string) $seat['name'])) === 1 ? null : $seat['name']),
                    'answers' => AnswerMapper::mapToNames($fields, $seat['answers']),
                ], $lineSeats)),
            ];
        }
    }

    /**
     * Snapshot of a line item whose date no longer exists, from the data NetzpEvents6 stored in the order. The event is
     * gone as well, so its ID stays empty.
     *
     * @param array<mixed> $payload
     * @param array<int, PayloadSeat> $seats
     *
     * @return array<string, mixed>|null
     */
    private function createDeletedDateSnapshot(?string $slotId, array $payload, array $seats): ?array
    {
        $from = \is_string($payload['from'] ?? null) && $payload['from'] !== '' ? $payload['from'] : null;
        if ($slotId === null || $from === null) {
            return null;
        }

        $until = \is_string($payload['until'] ?? null) && $payload['until'] !== '' ? $payload['until'] : $from;
        $attendees = array_values(array_map(static fn (array $seat): array => [
            'name' => preg_match('/^\(\d+\)$/', trim((string) $seat['name'])) === 1 ? null : $seat['name'],
            'answers' => array_column($seat['answers'], 'value', 'name'),
        ], $seats));

        return [
            'version' => self::SNAPSHOT_VERSION,
            'eventId' => '',
            'slotId' => $slotId,
            'title' => \is_string($payload['title'] ?? null) ? $payload['title'] : null,
            'teaser' => null,
            'startAt' => (new \DateTimeImmutable($from))->format(\DATE_ATOM),
            'endAt' => (new \DateTimeImmutable($until))->format(\DATE_ATOM),
            'timezone' => \is_string($payload['timezone'] ?? null) ? $payload['timezone'] : 'UTC',
            'collectAttendeeNames' => array_filter(array_column($attendees, 'name')) !== [],
            'location' => \is_string($payload['location'] ?? null) ? $payload['location'] : null,
            'address' => \is_string($payload['address'] ?? null) ? $payload['address'] : null,
            'attendees' => $attendees,
        ];
    }

    private function compareCounts(ImportPlan $plan, MigrationReport $report): void
    {
        $booked = [];
        $causes = [];
        foreach ($plan->participants as $participant) {
            $slotId = $participant['slotId'];
            if ($participant['status'] !== 'cancelled') {
                $booked[$slotId] = ($booked[$slotId] ?? 0) + 1;
            }

            $cause = match (true) {
                $participant['statusBeforeCancellation'] !== null => 'activeSeatsOnCancelledOrders',
                $participant['status'] === 'cancelled' => 'cancelledSeats',
                isset($plan->reconstructed[$participant['id']]) => 'reconstructedSeats',
                $participant['orderId'] === null => 'manualSeats',
                default => null,
            };
            if ($cause !== null) {
                $causes[$slotId][$cause] = ($causes[$slotId][$cause] ?? 0) + 1;
            }
        }

        foreach ($plan->slots as $slot) {
            $count = $booked[$slot['id']] ?? 0;
            if ($count !== $slot['netzpBooked']) {
                $report->warn('booked_count_differs', 'The imported active seats differ from NetzpEvents6\'s counter of the date; the causes list seats that can explain it.', ['slotId' => $slot['id'], 'netzp' => $slot['netzpBooked'], 'imported' => $count, 'causes' => $causes[$slot['id']] ?? []]);
            }

            if ($count > $slot['capacity']) {
                $report->warn('slot_overbooked', 'A date has more active seats than capacity; SwkwebEvents sells no more seats there until the capacity is raised.', ['slotId' => $slot['id'], 'capacity' => $slot['capacity'], 'seats' => $count]);
            }
        }
    }

    /**
     * NetzpEvents6's "anonymous" setting was global; SwkwebEvents asks per event.
     */
    private function getCollectAttendeeNames(MigrationReport $report): bool
    {
        $config = $this->reader->getConfig();
        $anonymous = (bool) ($config['global']['anonymous'] ?? false);

        foreach ($config as $scope => $values) {
            if ($scope !== 'global' && \array_key_exists('anonymous', $values) && (bool) $values['anonymous'] !== $anonymous) {
                $report->warn('anonymous_differs_per_channel', 'The anonymous setting differs per sales channel; events use the global value.', ['salesChannelId' => $scope]);
            }
        }

        return !$anonymous;
    }

    /**
     * @param array<string, Event> $events
     * @param array<string, string> $languages
     */
    private function countLanguages(array $events, array $languages, MigrationReport $report): void
    {
        foreach ($events as $event) {
            foreach (array_keys($event['translations']) as $languageId) {
                $report->count('eventTranslations.' . ($languages[$languageId] ?? $languageId));
            }
        }
    }

    private static function toAtom(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}

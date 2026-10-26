<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\ScheduleEstimator;
use DropTime\Http\Controller;
use DropTime\Http\HttpException;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\MeetRepository;

/**
 * 大会プログラムと結果のブラウズ。
 */
final class MeetController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('parent/meets', [
            'meets' => $this->meets()->all(),
        ]);
    }

    public function show(Request $request): Response
    {
        $meet = $this->requireMeet($request);
        $program = $this->meets()->programOf((int) $meet['id']);

        $estimates = ScheduleEstimator::estimate(array_map(
            static fn (array $event): array => [
                'id' => (int) $event['id'],
                'distance' => (int) $event['distance'],
                'heats' => (int) $event['heat_count'],
                'scheduled_time' => is_string($event['scheduled_time']) ? $event['scheduled_time'] : null,
            ],
            $program
        ));

        return $this->view('parent/meet', [
            'meet' => $meet,
            'program' => $program,
            'estimates' => $estimates,
            'favoriteEventIds' => $this->favoriteEventIds((int) $meet['id']),
        ]);
    }

    public function event(Request $request): Response
    {
        $meet = $this->requireMeet($request);
        $event = $this->meets()->findEvent((int) $meet['id'], $request->intParam('event'));

        if ($event === null) {
            throw HttpException::notFound('種目が見つかりません。');
        }

        $entries = $this->meets()->entriesOf((int) $event['id']);
        $results = $this->meets()->resultsOf((int) $event['id']);

        $splits = $this->meets()->splitsFor(array_map(
            static fn (array $row): int => (int) $row['id'],
            $results
        ));

        // 組ごとにまとめる。組が無いエントリーは 0 に寄せる。
        $heats = [];

        foreach ($entries as $entry) {
            $heats[(int) ($entry['heat'] ?? 0)][] = $entry;
        }

        ksort($heats);

        return $this->view('parent/event', [
            'meet' => $meet,
            'event' => $event,
            'heats' => $heats,
            'results' => $results,
            'splits' => $splits,
            'favoriteSwimmerIds' => $this->favoriteSwimmerIds(),
        ]);
    }

    private function meets(): MeetRepository
    {
        return new MeetRepository($this->context->db);
    }

    /** @return array<string, mixed> */
    private function requireMeet(Request $request): array
    {
        $meet = $this->meets()->find($request->intParam('meet'));

        if ($meet === null) {
            throw HttpException::notFound('大会が見つかりません。');
        }

        return $meet;
    }

    /**
     * お気に入り選手が出る種目の ID。プログラムで行を強調するのに使う。
     *
     * @return array<int, true>
     */
    private function favoriteEventIds(int $meetId): array
    {
        $swimmerIds = $this->favoriteSwimmerIds();

        if ($swimmerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($swimmerIds), '?'));

        $rows = $this->context->db->select(
            'SELECT DISTINCT en.event_id FROM entries en'
            . ' JOIN events e ON e.id = en.event_id'
            . ' WHERE e.meet_id = ? AND en.swimmer_id IN (' . $placeholders . ')',
            [$meetId, ...array_keys($swimmerIds)]
        );

        $ids = [];

        foreach ($rows as $row) {
            $ids[(int) $row['event_id']] = true;
        }

        return $ids;
    }

    /** @return array<int, true> */
    private function favoriteSwimmerIds(): array
    {
        $profileId = ProfileSession::currentId();

        if ($profileId === null) {
            return [];
        }

        $ids = [];

        foreach ((new FavoriteRepository($this->context->db))->favoriteIdsOf($profileId) as $id) {
            $ids[$id] = true;
        }

        return $ids;
    }
}

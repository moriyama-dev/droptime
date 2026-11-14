<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use DropTime\Db\Database;

/**
 * MeetProgram で読んだセッションと種目の並びを DB に書く。
 *
 * 大会のセッションは毎回まるごと書き直す（events.meet_session_id は外部キーで NULL に戻る）。
 * 種目は (event_number, round) で引き当てて、セッションとセッション内の位置を付ける。
 * DB に無い種目（取り込んでいないページ）は飛ばす。
 */
final class ProgramWriter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<array{code: string, name: string, date: ?string, start_time: ?string,
     *                   events: list<array{number: string, round: string, page: string}>}> $program
     * @return array{sessions: int, events: int}
     */
    public function write(int $meetId, array $program): array
    {
        if ($program === []) {
            return ['sessions' => 0, 'events' => 0];
        }

        /** @var array{sessions: int, events: int} $counts */
        $counts = $this->db->transaction(function (Database $db) use ($meetId, $program): array {
            $db->execute('DELETE FROM meet_sessions WHERE meet_id = :id', ['id' => $meetId]);

            $dates = array_values(array_unique(array_filter(array_column($program, 'date'))));
            sort($dates);
            $counts = ['sessions' => 0, 'events' => 0];

            foreach ($program as $order => $session) {
                $dayIndex = $session['date'] !== null ? (int) array_search($session['date'], $dates, true) + 1 : 1;

                $sessionId = $db->insert(
                    'INSERT INTO meet_sessions (meet_id, day, code, name, session_date, start_time, sort_order)'
                    . ' VALUES (:meet_id, :day, :code, :name, :session_date, :start_time, :sort_order)',
                    [
                        'meet_id' => $meetId,
                        'day' => $dayIndex,
                        'code' => mb_substr($session['code'], 0, 16),
                        'name' => mb_substr($session['name'], 0, 96),
                        'session_date' => $session['date'],
                        'start_time' => $session['start_time'],
                        'sort_order' => $order + 1,
                    ]
                );
                $counts['sessions']++;

                foreach ($session['events'] as $position => $event) {
                    $counts['events'] += $db->execute(
                        'UPDATE events SET meet_session_id = :session, session_position = :position'
                        . ' WHERE meet_id = :meet_id AND event_number = :number AND round = :round',
                        [
                            'session' => $sessionId,
                            'position' => $position + 1,
                            'meet_id' => $meetId,
                            'number' => $event['number'],
                            'round' => $event['round'],
                        ]
                    );
                }
            }

            return $counts;
        });

        return $counts;
    }
}

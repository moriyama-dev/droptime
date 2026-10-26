<?php

declare(strict_types=1);

namespace DropTime\Parser\HyTek;

use DropTime\Domain\Course;
use DropTime\Parser\ParsedEntry;
use DropTime\Parser\ParsedEvent;
use DropTime\Parser\ParsedMeet;
use DropTime\Parser\ParsedResultRow;
use DropTime\Parser\ParsedSplit;
use DropTime\Parser\ParsedSwimmer;
use DropTime\Parser\ParserInterface;
use DropTime\Parser\ParseResult;

/**
 * Hy-Tek MEET MANAGER が出力する結果ページ（HTML の <pre> テキスト）のパーサー。
 *
 * 1 行ずつ種類を判定する状態機械。複数イベントページを連結したテキストも
 * 1 大会として読める。予選ページ（Seed/Prelims 列）と決勝ページ
 * （Prelims/Finals 列）は round の違う別種目として返し、DB 側の
 * (meet, event_number, round) 一意キーに対応させる。
 *
 * 規則はすべて実データ（results.swimmeets.ca 2025-2026）から起こした。
 * フィクスチャ: tests/fixtures/hytek-results.*
 */
final class HyTekResultsParser implements ParserInterface
{
    private ParseResult $result;

    /** @var array<string, ParsedEvent> number|round → イベント（続きページを合流させる） */
    private array $events = [];

    /** @var array<string, mixed>|null 現在のイベントページの文脈 */
    private ?array $context = null;

    /** @var array<string, mixed>|null スプリット行を待っている結果行 */
    private ?array $pending = null;

    private ?string $meetName = null;
    private ?string $meetStart = null;
    private ?string $meetEnd = null;
    private ?string $licensee = null;

    /** @var array<string, int> 種目のコースの出現数。大会のコースは多数決。 */
    private array $courseVotes = [];

    private int $eventSequence = 0;

    /** チーム得点ブロックの中。次の Event ヘッダーまで読み飛ばす。 */
    private bool $skipUntilEvent = false;

    private bool $warnedSwimoff = false;

    public function name(): string
    {
        return 'hytek-results';
    }

    public function label(): string
    {
        return 'Hy-Tek MEET MANAGER 結果ページ';
    }

    public function confidence(string $text): int
    {
        $head = substr($text, 0, 20000);

        $hasEvent = preg_match('/^\s*\(?Event\s+\d+/m', $head) === 1;
        $hasColumns = preg_match('/^\s+(Name|Team)\b.*\b(Finals|Prelims)\b/m', $head) === 1;

        if ($hasEvent && $hasColumns) {
            return 85;
        }

        return $hasEvent ? 30 : 0;
    }

    public function parse(string $text): ParseResult
    {
        $this->result = new ParseResult($this->name());
        $this->events = [];
        $this->context = null;
        $this->pending = null;
        $this->meetName = null;
        $this->meetStart = null;
        $this->meetEnd = null;
        $this->licensee = null;
        $this->courseVotes = [];
        $this->eventSequence = 0;
        $this->skipUntilEvent = false;
        $this->warnedSwimoff = false;

        $normalized = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text);
        $lineNumber = 0;

        foreach (explode("\n", $normalized) as $line) {
            $lineNumber++;
            $this->consume($lineNumber, $line);
        }

        $this->flushPending();

        foreach ($this->events as $event) {
            $this->result->addEvent($event);
        }

        $this->finishMeet();

        return $this->result;
    }

    private function consume(int $number, string $line): void
    {
        // 種目ヘッダーはどの状態からでも最優先で拾う（スキップ状態も解除する）。
        $event = HyTekLine::eventHeader($line);

        if ($event !== null) {
            $this->flushPending();
            $this->skipUntilEvent = false;
            $this->beginEvent($event);
            $this->note($number, $event['continued'] ? '種目（続き）' : '種目');

            return;
        }

        $meet = HyTekLine::meetHeader($line);

        if ($meet !== null) {
            $this->captureMeet($meet);
            $this->note($number, '大会ヘッダー');

            return;
        }

        if (HyTekLine::isScoresHeader($line)) {
            $this->flushPending();
            $this->context = null;
            $this->skipUntilEvent = true;
            $this->note($number, 'チーム得点（無視）');

            return;
        }

        if ($this->skipUntilEvent) {
            $this->note($number, 'チーム得点（無視）');

            return;
        }

        $columns = HyTekLine::columnHeader($line);

        if ($columns !== null) {
            if ($this->context !== null) {
                $this->context['columns'] = $columns;
                $this->context['round'] = self::roundFor($columns);
            }

            $this->note($number, '列見出し');

            return;
        }

        $section = HyTekLine::sectionMarker($line);

        if ($section !== null) {
            $this->flushPending();

            if ($this->context !== null) {
                if ($section === 'swimoff') {
                    // スイムオフは記録として保存しない。行ごと未認識に落とすと
                    // 件数が膨らむだけなので、警告 1 回で区間全体を無視する。
                    $this->context['skip'] = true;

                    if (!$this->warnedSwimoff) {
                        $this->warnedSwimoff = true;
                        $this->result->addWarning('スイムオフの区間は取り込み対象外として無視しました。');
                    }
                } elseif ($section !== 'other') {
                    $this->context['skip'] = false;
                }
            }

            $this->note($number, 'セクション');

            return;
        }

        if ($this->context === null) {
            // イベントの外側。ライセンス行と装飾だけを許し、他は未認識。
            if (HyTekLine::isNoise($line)) {
                $this->note($number, '無視');
            } elseif (($who = HyTekLine::licensee($line)) !== null) {
                $this->licensee ??= $who;
                $this->note($number, 'ページ情報');
            } else {
                $this->unrecognized($number, $line);
            }

            return;
        }

        if ($this->context['skip'] === true) {
            $this->note($number, 'スキップ区間');

            return;
        }

        if ($this->context['header']['is_relay']) {
            $relay = HyTekLine::relayResult($line);

            if ($relay !== null) {
                $this->flushPending();
                $this->acceptRelay($number, $relay);

                return;
            }

            if (HyTekLine::isRelayLegs($line)) {
                // 泳者の内訳。v1 ではリレーは記録として個人に紐付けない。
                $this->note($number, 'リレー泳者');

                return;
            }
        } else {
            $individual = HyTekLine::individualResult($line);

            if ($individual !== null) {
                $this->flushPending();
                $this->acceptIndividual($number, $individual);

                return;
            }
        }

        $splits = HyTekLine::splits($line);

        if ($splits !== null && $this->pending !== null) {
            $this->pending['splits'] = array_merge($this->pending['splits'], $splits);
            $this->note($number, 'スプリット');

            return;
        }

        if (HyTekLine::isNoise($line)) {
            $this->note($number, '無視');

            return;
        }

        if (($who = HyTekLine::licensee($line)) !== null) {
            $this->licensee ??= $who;
            $this->note($number, 'ページ情報');

            return;
        }

        $this->unrecognized($number, $line);
    }

    /** @param array<string, mixed> $header */
    private function beginEvent(array $header): void
    {
        // イベントの実体は最初の結果行が来た時点で（round 確定後に）作る。
        // 先に作ると、予選ページの timed プレースホルダーが空のまま残る。
        $this->context = [
            'header' => $header,
            'columns' => null,
            'round' => 'timed',
            'skip' => false,
        ];
    }

    /** @param array{has_seed: bool, has_prelims: bool, has_finals: bool, has_points: bool} $columns */
    private static function roundFor(array $columns): string
    {
        if ($columns['has_seed'] && $columns['has_prelims']) {
            return 'prelim';
        }

        if ($columns['has_prelims'] && $columns['has_finals']) {
            return 'final';
        }

        return 'timed';
    }

    /** @param array<string, mixed> $header */
    private function eventFor(array $header, string $round): ParsedEvent
    {
        $key = $header['number'] . '|' . $round;

        if (!isset($this->events[$key])) {
            $this->eventSequence++;
            $numeric = (int) preg_replace('/[^0-9]/', '', $header['number']);
            $roundRank = match ($round) {
                'prelim' => 0,
                'semi' => 3,
                default => 5,
            };

            $this->events[$key] = new ParsedEvent(
                $header['number'],
                $round,
                $header['gender'],
                $header['distance'],
                $header['stroke'],
                $header['course'],
                $header['age_group'],
                $header['is_relay'],
                null,
                $numeric > 0 ? $numeric * 10 + $roundRank : $this->eventSequence * 10 + $roundRank,
            );

            $this->courseVotes[$header['course']->value] = ($this->courseVotes[$header['course']->value] ?? 0) + 1;
        }

        return $this->events[$key];
    }

    /**
     * 列見出しで round が確定したとき、イベントを正しい round に付け替える。
     */
    private function currentEvent(): ParsedEvent
    {
        return $this->eventFor($this->context['header'], $this->context['round']);
    }

    /** @param array<string, mixed> $row */
    private function acceptIndividual(int $number, array $row): void
    {
        $columns = $this->context['columns'];

        if ($columns === null) {
            // 列見出しを見ていないのに結果行が来た。解釈を誤るくらいなら落とす。
            $this->unrecognized($number, '（列見出し前の結果行）');

            return;
        }

        $parsed = $this->interpretTail($row['tail'], $columns);

        if ($parsed === null) {
            $this->unrecognized($number, implode(' ', [$row['last'] . ',', $row['first'], ...$row['tail']]));

            return;
        }

        $event = $this->currentEvent();

        $swimmer = new ParsedSwimmer(
            $row['last'],
            $row['first'],
            null,
            $row['age'],
            $event->gender === 'X' ? null : $event->gender,
            $row['team'],
            null,
        );

        if ($parsed['seed'] !== null) {
            $event->addEntry(new ParsedEntry(
                $swimmer,
                null,
                null,
                $parsed['seed']['time_cs'],
                $parsed['seed']['is_nt'],
                $row['team'],
                null,
            ));
        }

        $this->pending = [
            'event' => $event,
            'swimmer' => $swimmer,
            'team' => $row['team'],
            'letter' => null,
            'place' => $row['place'],
            'status' => $parsed['status'],
            'time_cs' => $parsed['time_cs'],
            'points' => $parsed['points'],
            'splits' => [],
        ];

        $this->note($number, '結果');
    }

    /** @param array<string, mixed> $row */
    private function acceptRelay(int $number, array $row): void
    {
        $columns = $this->context['columns'];

        if ($columns === null) {
            $this->unrecognized($number, '（列見出し前のリレー行）');

            return;
        }

        $parsed = $this->interpretTail($row['tail'], $columns);

        if ($parsed === null) {
            $this->unrecognized($number, implode(' ', [$row['team'], $row['letter'], ...$row['tail']]));

            return;
        }

        $event = $this->currentEvent();

        if ($parsed['seed'] !== null) {
            $event->addEntry(new ParsedEntry(
                null,
                null,
                null,
                $parsed['seed']['time_cs'],
                $parsed['seed']['is_nt'],
                $row['team'],
                $row['letter'],
            ));
        }

        $this->pending = [
            'event' => $event,
            'swimmer' => null,
            'team' => $row['team'],
            'letter' => $row['letter'],
            'place' => $row['place'],
            'status' => $parsed['status'],
            'time_cs' => $parsed['time_cs'],
            'points' => $parsed['points'],
            'splits' => [],
        ];

        $this->note($number, 'リレー結果');
    }

    /**
     * タイム欄のトークン列を列見出しに従って解釈する。
     *
     * @param list<string> $tail
     * @param array{has_seed: bool, has_prelims: bool, has_finals: bool, has_points: bool} $columns
     * @return array{seed: ?array{time_cs: ?int, is_nt: bool}, status: string,
     *               time_cs: ?int, points: ?float}|null
     */
    private function interpretTail(array $tail, array $columns): ?array
    {
        $index = 0;
        $seed = null;

        // 決勝ページの 1 列目は予選タイムで、シードとしては扱わない。
        $hasLeadColumn = $columns['has_seed'] || ($columns['has_prelims'] && $columns['has_finals']);
        $leadIsSeed = $columns['has_seed'];

        if ($hasLeadColumn) {
            $token = $tail[$index] ?? null;

            if ($token === null) {
                return null;
            }

            $lead = HyTekLine::seedToken($token);

            if ($lead === null) {
                return null;
            }

            if ($leadIsSeed) {
                $seed = $lead;
            }

            $index++;
        }

        $token = $tail[$index] ?? null;

        if ($token === null) {
            return null;
        }

        $result = HyTekLine::resultToken($token);

        if ($result === null) {
            return null;
        }

        $index++;
        $points = null;

        if (isset($tail[$index]) && preg_match('/^\d+(\.\d+)?$/', $tail[$index]) === 1) {
            $points = (float) $tail[$index];
            $index++;
        }

        // 想定より多いトークンが残る行は、列のずれの可能性があるので信用しない。
        if (isset($tail[$index])) {
            return null;
        }

        return [
            'seed' => $seed,
            'status' => $result['status'],
            'time_cs' => $result['time_cs'],
            'points' => $points,
        ];
    }

    /** 保留中の結果行を、集まったスプリットと一緒に確定させる。 */
    private function flushPending(): void
    {
        if ($this->pending === null) {
            return;
        }

        $p = $this->pending;
        $this->pending = null;

        /** @var ParsedEvent $event */
        $event = $p['event'];

        // スプリットの刻みは印字によって 50m ごとだったり 100m ごとだったり
        // する（例: 200m で 4 個なら 50m 刻み、2 個なら 100m 刻み）。
        // 距離を個数で均等割りし、割り切れない・単調増加でない場合は
        // 距離を推測せず捨てる（誤った距離を保存するくらいなら無いほうがよい）。
        $splits = [];

        if ($p['status'] === 'OK' && $p['splits'] !== []) {
            $count = count($p['splits']);
            $step = $event->distance % $count === 0 ? intdiv($event->distance, $count) : 0;

            if ($step >= 25 && $step % 25 === 0) {
                $previous = 0;

                foreach ($p['splits'] as $i => $cumulative) {
                    if ($cumulative <= $previous) {
                        $splits = [];

                        break;
                    }

                    $splits[] = new ParsedSplit($step * ($i + 1), $cumulative);
                    $previous = $cumulative;
                }
            }
        }

        $event->addResult(new ParsedResultRow(
            $p['swimmer'],
            $p['status'] === 'OK' ? $p['time_cs'] : null,
            $p['status'] === 'OK' ? $p['place'] : null,
            $p['status'],
            null,
            $p['points'],
            null,
            null,
            $p['team'],
            $p['letter'],
            $splits,
        ));
    }

    /** @param array{name: string, start: string, end: string} $meet */
    private function captureMeet(array $meet): void
    {
        $this->meetName ??= $meet['name'];
        $this->meetStart ??= $meet['start'];
        $this->meetEnd ??= $meet['end'];
    }

    private function finishMeet(): void
    {
        if ($this->meetName === null || $this->meetStart === null) {
            $this->result->addWarning('大会名と日付をヘッダーから読み取れませんでした。投入前に手で補ってください。');
        }

        arsort($this->courseVotes);
        $courseValue = array_key_first($this->courseVotes);
        $course = $courseValue !== null ? Course::tryFrom($courseValue) : null;

        if ($course === null) {
            return; // 種目が 1 つも無い。isEmpty のまま返す。
        }

        $this->result->setMeet(new ParsedMeet(
            $this->meetName ?? '',
            $this->meetStart ?? '',
            $course,
            $this->meetEnd,
            $this->licensee,
            null,
        ));
    }

    private function note(int $number, string $kind): void
    {
        $this->result->noteLine($number, $kind);
    }

    private function unrecognized(int $number, string $line): void
    {
        $this->result->addUnrecognized($number, $line);
        $this->result->noteLine($number, '未認識');
    }
}

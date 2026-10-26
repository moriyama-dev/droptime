<?php

declare(strict_types=1);

use DropTime\Domain\Course;
use DropTime\Domain\Stroke;
use DropTime\Parser\HyTek\HyTekLine;
use DropTime\Tests\Assert;

return [
    '大会ヘッダーを読む' => static function (): void {
        $m = HyTekLine::meetHeader('   2026 June Performance Meet - 6/5/2026 to 6/7/2026   ');
        Assert::same('2026 June Performance Meet', $m['name']);
        Assert::same('2026-06-05', $m['start']);
        Assert::same('2026-06-07', $m['end']);
    },
    '終了日の年が固定幅で切れていても開始日の年で補う' => static function (): void {
        $m = HyTekLine::meetHeader('2026 Central Region Division 2 Championships - 2/20/2026 to 2/22/202');
        Assert::same('2026-02-22', $m['end']);
    },
    'ライセンス行（時刻なしも可）' => static function (): void {
        Assert::same(
            'Toronto Pan Am Sports Centre',
            HyTekLine::licensee(' Toronto Pan Am Sports Centre - Site License      6/6/2026 - 2:42 PM')
        );
        Assert::same(
            'North York Aquatic Club',
            HyTekLine::licensee(' North York Aquatic Club                                   2/23/2026')
        );
    },
    '種目ヘッダー（個人・リレー・続き・タイムトライアル）' => static function (): void {
        $e = HyTekLine::eventHeader(' Event 47  Girls 10 & Under 50 LC Meter Freestyle');
        Assert::same('47', $e['number']);
        Assert::same('F', $e['gender']);
        Assert::same('10 & Under', $e['age_group']);
        Assert::same(50, $e['distance']);
        Assert::same(Course::LCM, $e['course']);
        Assert::same(Stroke::FREE, $e['stroke']);
        Assert::false($e['is_relay']);

        $r = HyTekLine::eventHeader(' Event 21  Mixed 10 & Under 200 SC Meter Freestyle Relay');
        Assert::true($r['is_relay']);
        Assert::same('X', $r['gender']);

        $c = HyTekLine::eventHeader('(Event 7  Girls 10 & Under 200 SC Meter Freestyle continued)');
        Assert::true($c['continued']);

        $tt = HyTekLine::eventHeader(' Event 3  Women 15 & Over 50 LC Meter Freestyle Time Trial');
        Assert::same('3TT', $tt['number'], 'タイムトライアルは番号で本種目と区別する');
    },
    '個人結果行（ニックネーム・複語チーム・名前欠けも）' => static function (): void {
        $r = HyTekLine::individualResult('  1 Dese, Zomudaz          10 NYAC                36.20      34.82  ');
        Assert::same(1, $r['place']);
        Assert::same('Dese', $r['last']);
        Assert::same(['36.20', '34.82'], $r['tail']);

        $nick = HyTekLine::individualResult(' 49 Zolo, Lebep (Gufene)   12 ZORC              1:17.90    1:17.27  ');
        Assert::same('Lebep (Gufene)', $nick['first']);

        $multi = HyTekLine::individualResult('  7 Vazoto, Dokubu         14 Pointe-Claire Sw  1:05.00    1:04.44  ');
        Assert::same('Pointe-Claire Sw', $multi['team']);

        $dq = HyTekLine::individualResult(' -- Zedoseme, Biti          8 ZORC              2:30.00         DQ  ');
        Assert::null($dq['place']);
        Assert::same(['2:30.00', 'DQ'], $dq['tail']);
    },
    'パラ分類コードが融着した破損行は拾わない' => static function (): void {
        Assert::null(HyTekLine::individualResult(' 35 Kavtaradze _s9sb8sm9,  16 Flames/A,3,5,12,  3:46.59    3:52.27  '));
        Assert::null(HyTekLine::individualResult('  9 Cosgriffe_S10SB9SM10,  20 OAK/12,+'));
    },
    'リレー結果行と泳者行' => static function (): void {
        $r = HyTekLine::relayResult("  1 RHAC  'A'                           2:15.89    2:16.44   48  ");
        Assert::same('RHAC', $r['team']);
        Assert::same('A', $r['letter']);
        Assert::true(HyTekLine::isRelayLegs('     1) Vumo, Lidi R M10              2) Vi, Folir M10'));
    },
    'スプリット行（累積のみ拾い、DQ 混じりも認識する）' => static function (): void {
        Assert::same([3526, 7038], HyTekLine::splits('                 35.26      1:10.38 (35.12)'));
        Assert::same([], HyTekLine::splits('            DQ (1:48.20)'), 'DQ だけの行は空として認識');
        Assert::null(HyTekLine::splits(' 1 Dese, Zomudaz 10 NYAC 36.20 34.82'));
    },
    'タイム欄トークン（状態・エキシビション・判定タイム）' => static function (): void {
        Assert::same(['status' => 'OK', 'time_cs' => 3482], HyTekLine::resultToken('34.82'));
        Assert::same(['status' => 'DQ', 'time_cs' => null], HyTekLine::resultToken('DQ'));
        Assert::same(['status' => 'OK', 'time_cs' => 14748], HyTekLine::resultToken('X2:27.48'));
        Assert::same(['status' => 'OK', 'time_cs' => 2842], HyTekLine::resultToken('J28.42'));
        Assert::same(['time_cs' => null, 'is_nt' => true], HyTekLine::seedToken('NT'));
    },
    '列見出しとセクション' => static function (): void {
        $c = HyTekLine::columnHeader('    Name                  Age Team                 Seed     Finals Points ');
        Assert::true($c['has_seed'] && $c['has_finals'] && $c['has_points']);
        Assert::false($c['has_prelims']);
        Assert::same('prelim', HyTekLine::sectionMarker('                      === Preliminaries ===   '));
        Assert::same('final', HyTekLine::sectionMarker('  === A - Final ===  '));
        Assert::same('swimoff', HyTekLine::sectionMarker(' === Swim-off ==='));
        Assert::same('other', HyTekLine::sectionMarker(' === - Time Trial ==='));
    },
    'チーム得点見出しと装飾行' => static function (): void {
        Assert::true(HyTekLine::isScoresHeader('   Combined Team Scores - Through Event 53  '));
        Assert::true(HyTekLine::isNoise('=========================================='));
        Assert::true(HyTekLine::isNoise(' Meet Qualifying:  1:40.01 - 2:00.00'));
        Assert::true(HyTekLine::isNoise(' Friday 2/20/2026'));
    },
];

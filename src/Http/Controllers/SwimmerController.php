<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\Progression;
use DropTime\Http\Controller;
use DropTime\Http\Flash;
use DropTime\Http\HttpException;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\SwimmerRepository;

/**
 * 選手ページ。記録の一覧・自己ベスト・タイム推移。
 */
final class SwimmerController extends Controller
{
    public function show(Request $request): Response
    {
        $swimmer = $this->requireSwimmer($request);
        $results = $this->swimmers()->resultsOf((int) $swimmer['id']);

        // 大会ごとにまとめる（新しい順のまま）。
        $meetGroups = [];

        foreach ($results as $row) {
            $meetId = (int) $row['meet_id'];
            $meetGroups[$meetId]['meet'] = [
                'id' => $meetId,
                'name' => $row['meet_name'],
                'start_date' => $row['start_date'],
            ];
            $meetGroups[$meetId]['results'][] = $row;
        }

        return $this->view('parent/swimmer', [
            'swimmer' => $swimmer,
            'meetGroups' => array_values($meetGroups),
            'shapes' => Progression::build($results),
            'isFavorite' => $this->isFavorite((int) $swimmer['id']),
            'flash' => Flash::pull(),
        ]);
    }

    /**
     * 旧「タイム推移」のアドレス。
     *
     * 推移だけの画面は成績タブ（順位＋推移）に統合した。ブックマークや
     * 古いリンクが死なないよう、ここは移動先へ送るだけにしてある。
     */
    public function progress(Request $request): Response
    {
        $swimmer = $this->requireSwimmer($request);

        return $this->redirectToRoute('scores.show', ['swimmer' => (int) $swimmer['id']]);
    }

    private function swimmers(): SwimmerRepository
    {
        return new SwimmerRepository($this->context->db);
    }

    /** @return array<string, mixed> */
    private function requireSwimmer(Request $request): array
    {
        $swimmer = $this->swimmers()->find($request->intParam('swimmer'));

        if ($swimmer === null) {
            throw HttpException::notFound('選手が見つかりません。');
        }

        return $swimmer;
    }

    private function isFavorite(int $swimmerId): bool
    {
        $profileId = ProfileSession::currentId();

        if ($profileId === null) {
            return false;
        }

        return in_array($swimmerId, (new FavoriteRepository($this->context->db))->favoriteIdsOf($profileId), true);
    }
}

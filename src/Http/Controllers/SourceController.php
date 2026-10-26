<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Evidence\EvidenceStore;
use DropTime\Http\Controller;
use DropTime\Http\HttpException;
use DropTime\Http\Request;
use DropTime\Http\Response;

/**
 * 「データの出典」。このアプリの記録が、ネット上で誰でも見られた情報から
 * 作られていることを示す画面。
 *
 * 取得元ページを Cookie もログインも無しで開いたときのスクリーンショット・
 * HTML・HTTP 応答・SHA-256 を見せ、その場でハッシュと台帳の鎖を検証する。
 */
final class SourceController extends Controller
{
    public function index(Request $request): Response
    {
        $store = EvidenceStore::fromConfig();
        $meets = $this->context->db->select('SELECT id, name, start_date, source_file FROM meets ORDER BY start_date DESC');
        $groups = [];

        foreach ($meets as $meet) {
            $groups[(int) $meet['id']] = ['meet' => $meet, 'captures' => []];
        }

        $other = [];

        foreach ($store->all() as $capture) {
            $placed = false;

            foreach ((array) ($capture['meet_ids'] ?? []) as $meetId) {
                if (isset($groups[(int) $meetId])) {
                    $groups[(int) $meetId]['captures'][] = $capture;
                    $placed = true;
                }
            }

            if (!$placed) {
                $other[] = $capture;
            }
        }

        // 原本 → 大会トップ → 一覧 → 各結果ページの順に並べる。
        foreach ($groups as &$group) {
            usort($group['captures'], static fn (array $a, array $b): int =>
                [self::order($a), (string) $a['url']] <=> [self::order($b), (string) $b['url']]);
        }
        unset($group);

        return $this->view('parent/sources', [
            'groups' => array_values($groups),
            'other' => $other,
            'ledger' => $store->verifyLedger(),
        ]);
    }

    public function show(Request $request): Response
    {
        $store = EvidenceStore::fromConfig();
        $id = $request->param('capture');
        $capture = $store->find($id);

        if ($capture === null) {
            throw HttpException::notFound('出典の記録が見つかりません。');
        }

        $ledger = $store->verifyLedger();

        return $this->view('parent/source', [
            'capture' => $capture,
            'check' => $store->verify($id),
            'ledger' => $ledger,
            'chainHash' => $ledger['by_id'][$id] ?? null,
            'meets' => $this->context->db->select('SELECT id, name FROM meets'),
        ]);
    }

    /**
     * 保存したファイルを返す。スクリーンショットは画像として、HTML は
     * 実行させないよう必ずテキストのダウンロードとして返す（第三者の HTML を
     * このアプリのオリジンで描画すると、中のスクリプトが動きうるため）。
     */
    public function file(Request $request): Response
    {
        $store = EvidenceStore::fromConfig();
        $id = $request->param('capture');
        $name = $request->queryString('name');
        $path = $store->find($id) !== null ? $store->filePath($id, $name) : null;

        if ($path === null) {
            throw HttpException::notFound('ファイルが見つかりません。');
        }

        $body = (string) file_get_contents($path);
        $response = new Response($body, 200);

        if (str_ends_with($name, '.png')) {
            return $response
                ->withHeader('Content-Type', 'image/png')
                ->withHeader('Cache-Control', 'private, max-age=86400');
        }

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $id . '-' . basename($name) . '.txt"');
    }

    private static function order(array $capture): int
    {
        $url = (string) $capture['url'];

        return match (true) {
            ($capture['kind'] ?? '') === 'originals' => 0,
            str_ends_with($url, '/') => 1,
            str_contains($url, 'evtindex') => 2,
            default => 3,
        };
    }
}

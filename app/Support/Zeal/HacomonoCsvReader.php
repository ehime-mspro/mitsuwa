<?php

namespace App\Support\Zeal;

class HacomonoCsvReader
{
    /**
     * hacomono形式CSVを連想配列の配列に変換する。
     * - 文字コード自動判定（UTF-8 / SJIS-win / SJIS / EUC-JP）→ UTF-8 へ変換
     * - 先頭 BOM 除去
     * - 引用フィールド内の改行に対応（fgetcsv 使用）
     * - 各行はヘッダー名をキーにした配列。列数が足りない場合は空文字で補完。
     *
     * @return array<int,array<string,string>>
     */
    public static function read(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("CSVを読み込めません: {$path}");
        }

        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('CSV解析用の一時ストリームを開けませんでした');
        }
        fwrite($fh, $content);
        rewind($fh);

        // escape='' で RFC 4180 準拠のパース（メモ内のバックスラッシュを誤エスケープしない）
        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($header === false) {
            fclose($fh);
            return [];
        }
        // ヘッダーキー前後の空白を除去（' ID ' のような列名でも $row['ID'] で引けるように）
        $header = array_map('trim', $header);
        $colCount = count($header);

        $rows = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            // 完全な空行はスキップ
            if (count(array_filter($cells, static fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            $cells = array_slice($cells, 0, $colCount);
            $cells = array_pad($cells, $colCount, '');
            $rows[] = array_combine($header, array_map(static fn ($c) => (string) ($c ?? ''), $cells));
        }
        fclose($fh);

        return $rows;
    }
}

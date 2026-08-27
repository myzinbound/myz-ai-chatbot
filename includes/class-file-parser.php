<?php
/**
 * ファイルからテキストを抽出するパーサー
 * 対応形式: PDF, XLSX, DOCX, CSV, TXT, MD
 */

if (!defined('ABSPATH')) exit;

class MYZ_Chatbot_File_Parser {

    const MAX_CHARS_PER_FILE = 30000;

    public static function parse($file_path, $original_name = '') {
        if (!file_exists($file_path)) {
            return ['error' => 'ファイルが見つかりません'];
        }

        $ext = strtolower(pathinfo($original_name ?: $file_path, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'pdf':
                $result = self::parse_pdf($file_path);
                break;
            case 'xlsx':
                $result = self::parse_xlsx($file_path);
                break;
            case 'docx':
                $result = self::parse_docx($file_path);
                break;
            case 'csv':
                $result = self::parse_csv($file_path);
                break;
            case 'txt':
            case 'md':
            case 'markdown':
                $result = self::parse_text($file_path);
                break;
            default:
                return ['error' => '対応していないファイル形式です（' . esc_html($ext) . '）'];
        }

        if (isset($result['error'])) return $result;

        $text = isset($result['text']) ? $result['text'] : '';
        $truncated = false;
        if (mb_strlen($text) > self::MAX_CHARS_PER_FILE) {
            $text = mb_substr($text, 0, self::MAX_CHARS_PER_FILE) . "\n...(以下省略)";
            $truncated = true;
        }

        return ['text' => $text, 'truncated' => $truncated];
    }

    /**
     * XLSX (Excel) パース
     */
    private static function parse_xlsx($file_path) {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'サーバーでZipArchive拡張が利用できません'];
        }
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return ['error' => 'XLSXファイルを開けませんでした'];
        }

        // 共有文字列
        $shared = [];
        $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss_xml !== false) {
            $prev = libxml_use_internal_errors(true);
            $ss = simplexml_load_string($ss_xml);
            libxml_use_internal_errors($prev);
            if ($ss !== false && isset($ss->si)) {
                foreach ($ss->si as $si) {
                    $val = '';
                    if (isset($si->t)) {
                        $val = (string) $si->t;
                    }
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $val .= (string) $r->t;
                        }
                    }
                    $shared[] = $val;
                }
            }
        }

        // シート名
        $sheet_names = [];
        $wb_xml = $zip->getFromName('xl/workbook.xml');
        if ($wb_xml !== false) {
            if (preg_match_all('/<sheet\s+[^>]*name="([^"]+)"/i', $wb_xml, $m)) {
                $sheet_names = $m[1];
            }
        }

        $text = '';
        $sheet_idx = 0;
        while (true) {
            $sheet_xml = $zip->getFromName('xl/worksheets/sheet' . ($sheet_idx + 1) . '.xml');
            if ($sheet_xml === false) break;

            $sheet_name = isset($sheet_names[$sheet_idx]) ? $sheet_names[$sheet_idx] : ('シート' . ($sheet_idx + 1));
            $text .= "\n【シート: " . $sheet_name . "】\n";

            $prev = libxml_use_internal_errors(true);
            $sheet = simplexml_load_string($sheet_xml);
            libxml_use_internal_errors($prev);

            if ($sheet !== false && isset($sheet->sheetData->row)) {
                foreach ($sheet->sheetData->row as $row) {
                    $row_text = [];
                    foreach ($row->c as $cell) {
                        $type = (string) $cell['t'];
                        $val = '';
                        if ($type === 's') {
                            $idx = (int) $cell->v;
                            $val = isset($shared[$idx]) ? $shared[$idx] : '';
                        } elseif ($type === 'inlineStr') {
                            $val = isset($cell->is->t) ? (string) $cell->is->t : '';
                        } elseif ($type === 'str') {
                            $val = (string) $cell->v;
                        } else {
                            $val = (string) $cell->v;
                        }
                        $val = trim($val);
                        if ($val !== '') $row_text[] = $val;
                    }
                    if (!empty($row_text)) {
                        $text .= implode(' | ', $row_text) . "\n";
                    }
                }
            }
            $sheet_idx++;
        }
        $zip->close();

        $text = trim($text);
        if (empty($text)) {
            return ['error' => 'XLSXからテキストを抽出できませんでした（空のシートの可能性）'];
        }
        return ['text' => $text];
    }

    /**
     * DOCX (Word) パース
     */
    private static function parse_docx($file_path) {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'サーバーでZipArchive拡張が利用できません'];
        }
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return ['error' => 'DOCXファイルを開けませんでした'];
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return ['error' => 'DOCXのdocument.xmlが見つかりません'];
        }

        // 段落・改行を保持
        $xml = preg_replace('/<w:tab\s*\/>/i', "\t", $xml);
        $xml = preg_replace('/<w:br\s*\/>/i', "\n", $xml);
        $xml = preg_replace('/<\/w:p>/i', "\n", $xml);

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
        $text = trim($text);

        if (empty($text)) {
            return ['error' => 'DOCXからテキストを抽出できませんでした'];
        }
        return ['text' => $text];
    }

    /**
     * PDF パース（簡易・純PHP）
     * 注意: テキスト埋め込みPDFのみ対応。画像PDFや一部の日本語PDFは抽出できない場合あり。
     */
    private static function parse_pdf($file_path) {
        $content = @file_get_contents($file_path);
        if ($content === false || strlen($content) < 8) {
            return ['error' => 'PDFファイルを読み込めませんでした'];
        }
        if (strncmp($content, '%PDF-', 5) !== 0) {
            return ['error' => 'PDF形式ではありません'];
        }

        $text = '';
        // すべての stream...endstream ブロックを抽出
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
            foreach ($streams[1] as $stream) {
                $decoded = self::pdf_inflate($stream);
                if ($decoded === '') $decoded = $stream;

                // (text) Tj 形式
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $decoded, $m)) {
                    foreach ($m[1] as $t) {
                        $text .= self::pdf_decode_string($t);
                    }
                    $text .= "\n";
                }
                // [(text) num (text)] TJ 形式
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $m)) {
                    foreach ($m[1] as $arr) {
                        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $arr, $sub)) {
                            foreach ($sub[1] as $t) {
                                $text .= self::pdf_decode_string($t);
                            }
                            $text .= "\n";
                        }
                    }
                }
            }
        }

        // 制御文字を除去・整理
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
        $text = trim($text);

        if (mb_strlen($text) < 10) {
            return ['error' => 'PDFからテキストを抽出できませんでした。画像化されたPDFや一部の日本語PDFはこの方式で抽出できません。テキストをコピーしてTXTファイルとしてアップロードするか、URLで学習させてください。'];
        }
        return ['text' => $text];
    }

    /**
     * PDFストリームのFlate解凍を試みる
     */
    private static function pdf_inflate($stream) {
        // gzuncompress（zlibヘッダー付き）
        $r = @gzuncompress($stream);
        if ($r !== false) return $r;
        // 先頭の zlib ヘッダー2バイトをスキップしてraw inflate
        if (strlen($stream) > 2) {
            $r = @gzinflate(substr($stream, 2));
            if ($r !== false) return $r;
        }
        // rawそのまま
        $r = @gzinflate($stream);
        if ($r !== false) return $r;
        return '';
    }

    /**
     * PDF文字列のエスケープを解除
     */
    private static function pdf_decode_string($s) {
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $s);
        $replacements = [
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
            '\\(' => '(',  '\\)' => ')',  '\\\\' => '\\',
        ];
        return strtr($s, $replacements);
    }

    /**
     * CSV パース
     */
    private static function parse_csv($file_path) {
        $raw = @file_get_contents($file_path);
        if ($raw === false || $raw === '') {
            return ['error' => 'CSVファイルを読み込めませんでした'];
        }
        // BOM除去
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        // SJIS等の自動判定→UTF-8
        $enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'ISO-2022-JP'], true);
        if ($enc && $enc !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $text = '';
        $headers = [];
        $first = true;
        foreach ($lines as $line) {
            if ($line === '') continue;
            $row = str_getcsv($line, ',', '"', '\\');
            if ($first) {
                $headers = $row;
                $text .= '【項目】' . implode(' | ', $row) . "\n";
                $first = false;
                continue;
            }
            $is_empty = true;
            foreach ($row as $c) { if (trim($c) !== '') { $is_empty = false; break; } }
            if ($is_empty) continue;

            if (!empty($headers)) {
                $parts = [];
                foreach ($row as $j => $cell) {
                    $cell = trim($cell);
                    if ($cell === '') continue;
                    $h = isset($headers[$j]) ? $headers[$j] : ('列' . ($j + 1));
                    $parts[] = $h . ': ' . $cell;
                }
                if (!empty($parts)) $text .= implode(' / ', $parts) . "\n";
            } else {
                $text .= implode(' | ', $row) . "\n";
            }
        }
        $text = trim($text);
        if (empty($text)) return ['error' => 'CSVから内容を抽出できませんでした'];
        return ['text' => $text];
    }

    /**
     * TXT/Markdown パース
     */
    private static function parse_text($file_path) {
        $raw = @file_get_contents($file_path);
        if ($raw === false) return ['error' => 'ファイルを読み込めませんでした'];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'ISO-2022-JP'], true);
        if ($enc && $enc !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
        }
        $raw = trim($raw);
        if (empty($raw)) return ['error' => 'ファイルが空です'];
        return ['text' => $raw];
    }
}

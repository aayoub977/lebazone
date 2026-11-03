<?php

namespace App\Services;

use App\Services\PurchasingCalculator as Calc;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Custom (Lebazone): Purchasing reports + Excel / CSV / PDF exports. */
class PurchasingReports
{
    public const REPORTS = ['items' => 'Landed cost by item', 'supplier' => 'Spend by supplier', 'origin' => 'Spend by origin country', 'shipment' => 'Shipments report', 'summary' => 'Summary'];
    private const NUMFMT = ['int' => '#,##0', 'n3' => '0.000', 'm' => '#,##0.00', 'm3' => '#,##0.000', 'pct' => '0.0%'];
    private array $tmp = [];

    public function __construct(private Calc $c) {}

    public function __destruct()
    {
        foreach ($this->tmp as $f) @unlink($f);
    }

    private function filtered(array $f): array
    {
        $items = array_filter($this->c->items, function ($it) use ($f) {
            $d = substr((string) $it['created_at'], 0, 10);
            if (!empty($f['from']) && $d < $f['from']) return false;
            if (!empty($f['to']) && $d > $f['to']) return false;
            if (!empty($f['supplier_id']) && (string) ($it['supplier_id'] ?? '') !== ($f['supplier_id'] === '-' ? '' : (string) $f['supplier_id'])) return false;
            if (!empty($f['shipment_id']) && (string) ($it['shipment_id'] ?? '') !== ($f['shipment_id'] === '-' ? '' : (string) $f['shipment_id'])) return false;
            if (!empty($f['status']) && ($it['status'] ?: 'Quote') !== $f['status']) return false;
            if (!empty($f['origin']) && $this->c->originOf($it) !== ($f['origin'] === '-' ? '' : $f['origin'])) return false;
            return true;
        });
        uasort($items, fn ($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));
        return $items;
    }

    private function filterText(array $f): string
    {
        $p = [];
        if (!empty($f['from']) || !empty($f['to'])) $p[] = 'Added ' . ($f['from'] ?: 'start') . ' to ' . ($f['to'] ?: 'today');
        if (!empty($f['supplier_id'])) $p[] = 'Supplier: ' . ($f['supplier_id'] === '-' ? 'none' : ($this->c->suppliers[$f['supplier_id']]['name'] ?? '?'));
        if (!empty($f['shipment_id'])) $p[] = 'Shipment: ' . ($f['shipment_id'] === '-' ? 'none' : ($this->c->shipments[$f['shipment_id']]['name'] ?? '?'));
        if (!empty($f['status'])) $p[] = 'Status: ' . $f['status'];
        if (!empty($f['origin'])) $p[] = 'Origin: ' . ($f['origin'] === '-' ? 'not set' : $f['origin']);
        $cur = (string) $this->c->setting('currency', '');
        return ($p ? implode(' · ', $p) : 'All items') . ($cur ? " · Amounts in $cur" : '');
    }

    public function build(string $type, array $f): array
    {
        $type = array_key_exists($type, self::REPORTS) ? $type : 'items';
        $R = $this->c->computeAll();
        $list = $this->filtered($f);
        $ids = array_keys($list);
        $T = fn (string $k, ?array $a = null) => Calc::sumOf($a ?? $ids, $R, $k);
        $qtyOf = fn (array $a) => array_sum(array_map(fn ($id) => $R[$id]['total'] !== null ? $R[$id]['qty'] : 0, $a));
        $avg = function (array $a) use ($T, $qtyOf) { $q = $qtyOf($a); $t = $T('total', $a); return $q && $t !== null ? $t / $q : null; };
        $grand = $T('total') ?? 0;
        $group = function (callable $key) use ($list) { $g = []; foreach ($list as $id => $it) $g[$key($it)][] = $id; return $g; };
        $totals = null;

        if ($type === 'items') {
            $columns = [['h' => 'Item', 'k' => 'name'], ['h' => 'Variants', 'k' => 'variants'], ['h' => 'Supplier', 'k' => 'supplier'], ['h' => 'Origin', 'k' => 'origin'],
                ['h' => 'Shipment', 'k' => 'shipment'], ['h' => 'Status', 'k' => 'status'], ['h' => 'Pieces', 'k' => 'pieces', 't' => 'int'], ['h' => 'CBM', 'k' => 'cbm', 't' => 'n3'],
                ['h' => 'Rate / CBM', 'k' => 'rate', 't' => 'm'], ['h' => 'Goods', 'k' => 'goods', 't' => 'm'], ['h' => 'Freight', 'k' => 'freight', 't' => 'm'],
                ['h' => 'Delivery', 'k' => 'delivery', 't' => 'm'], ['h' => 'Total cost', 'k' => 'total', 't' => 'm'], ['h' => 'Cost / piece', 'k' => 'per', 't' => 'm3'],
                ['h' => 'Sell price', 'k' => 'sell', 't' => 'm'], ['h' => 'Margin', 'k' => 'margin', 't' => 'pct']];
            $rows = [];
            foreach ($list as $id => $it) {
                $r = $R[$id];
                $rows[] = ['_pic' => $it['picture'], 'name' => $it['name'], 'variants' => (string) $it['variants'], 'supplier' => $this->c->suppliers[$it['supplier_id']]['name'] ?? '',
                    'origin' => $r['origin'], 'shipment' => $this->c->shipments[$it['shipment_id']]['name'] ?? '', 'status' => $it['status'] ?: 'Quote',
                    'pieces' => $r['total_items'], 'cbm' => $r['cbm'], 'rate' => $r['cbm'] !== null ? $r['rate'] : null, 'goods' => $r['item_cost'], 'freight' => $r['freight'],
                    'delivery' => $r['delivery'], 'total' => $r['total'], 'per' => $r['per_item'], 'sell' => $r['sell'], 'margin' => $r['margin']];
            }
            $totals = ['name' => 'Total', 'pieces' => $T('total_items'), 'cbm' => $T('cbm'), 'goods' => $T('item_cost'), 'freight' => $T('freight'), 'delivery' => $T('delivery'), 'total' => $T('total'), 'per' => $avg($ids)];
        } elseif ($type === 'supplier' || $type === 'origin') {
            $bySup = $type === 'supplier';
            $g = $group(fn ($it) => $bySup ? ((int) $it['supplier_id'] && isset($this->c->suppliers[$it['supplier_id']]) ? (string) $it['supplier_id'] : '') : $this->c->originOf($it));
            $columns = $bySup ? [['h' => 'Supplier', 'k' => 'label'], ['h' => 'Origin', 'k' => 'origin'], ['h' => 'Platform', 'k' => 'platform']] : [['h' => 'Origin country', 'k' => 'label'], ['h' => 'Suppliers', 'k' => 'sups', 't' => 'int']];
            $columns = array_merge($columns, [['h' => 'Item lines', 'k' => 'lines', 't' => 'int'], ['h' => 'Pieces', 'k' => 'pieces', 't' => 'int'], ['h' => 'CBM', 'k' => 'cbm', 't' => 'n3'],
                ['h' => 'Goods', 'k' => 'goods', 't' => 'm'], ['h' => 'Freight', 'k' => 'freight', 't' => 'm'], ['h' => 'Delivery', 'k' => 'delivery', 't' => 'm'],
                ['h' => 'Landed total', 'k' => 'total', 't' => 'm'], ['h' => 'Avg cost / piece', 'k' => 'per', 't' => 'm3'], ['h' => 'Share of spend', 'k' => 'share', 't' => 'pct']]);
            $rows = [];
            foreach ($g as $k => $a) {
                $s = $this->c->suppliers[$k] ?? null;
                $rows[] = ['label' => $bySup ? ($s['name'] ?? 'No supplier') : ($k !== '' ? $k : 'Origin not set'), 'origin' => $s['country'] ?? '', 'platform' => $s['platform'] ?? '',
                    'sups' => count(array_unique(array_filter(array_map(fn ($id) => $list[$id]['supplier_id'], $a)))), 'lines' => count($a),
                    'pieces' => $T('total_items', $a), 'cbm' => $T('cbm', $a), 'goods' => $T('item_cost', $a), 'freight' => $T('freight', $a), 'delivery' => $T('delivery', $a),
                    'total' => $T('total', $a), 'per' => $avg($a), 'share' => $grand ? ($T('total', $a) ?? 0) / $grand : null];
            }
            usort($rows, fn ($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
            $totals = ['label' => 'Total', 'lines' => count($list), 'pieces' => $T('total_items'), 'cbm' => $T('cbm'), 'goods' => $T('item_cost'), 'freight' => $T('freight'), 'delivery' => $T('delivery'), 'total' => $T('total'), 'per' => $avg($ids), 'share' => $grand ? 1 : null];
        } elseif ($type === 'shipment') {
            $g = $group(fn ($it) => (int) $it['shipment_id'] && isset($this->c->shipments[$it['shipment_id']]) ? (string) $it['shipment_id'] : '');
            $columns = [['h' => 'Shipment', 'k' => 'label'], ['h' => 'Status', 'k' => 'status'], ['h' => 'Ship date', 'k' => 'date'], ['h' => 'ETA', 'k' => 'eta'], ['h' => 'Forwarder', 'k' => 'fw'],
                ['h' => 'Item lines', 'k' => 'lines', 't' => 'int'], ['h' => 'Pieces', 'k' => 'pieces', 't' => 'int'], ['h' => 'CBM', 'k' => 'cbm', 't' => 'n3'], ['h' => 'Goods', 'k' => 'goods', 't' => 'm'],
                ['h' => 'Freight', 'k' => 'freight', 't' => 'm'], ['h' => 'Delivery', 'k' => 'delivery', 't' => 'm'], ['h' => 'Landed total', 'k' => 'total', 't' => 'm'], ['h' => 'Avg cost / piece', 'k' => 'per', 't' => 'm3']];
            $rows = [];
            foreach ($g as $k => $a) {
                $s = $this->c->shipments[$k] ?? [];
                $rows[] = ['label' => $k !== '' ? ($s['name'] ?? 'Unnamed') : 'Not in a shipment', 'status' => $k !== '' ? ($s['status'] ?? 'Planning') : '', 'date' => $s['ship_date'] ?? '', 'eta' => $s['eta'] ?? '',
                    'fw' => $s['forwarder'] ?? '', 'lines' => count($a), 'pieces' => $T('total_items', $a), 'cbm' => $T('cbm', $a), 'goods' => $T('item_cost', $a), 'freight' => $T('freight', $a),
                    'delivery' => $T('delivery', $a), 'total' => $T('total', $a), 'per' => $avg($a)];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
            $totals = ['label' => 'Total', 'lines' => count($list), 'pieces' => $T('total_items'), 'cbm' => $T('cbm'), 'goods' => $T('item_cost'), 'freight' => $T('freight'), 'delivery' => $T('delivery'), 'total' => $T('total'), 'per' => $avg($ids)];
        } else {
            $columns = [['h' => 'Measure', 'k' => 'measure'], ['h' => 'Value', 'k' => 'value', 't' => 'row']];
            $prof = array_filter($ids, fn ($id) => $R[$id]['profit'] !== null);
            $st = [];
            foreach ($list as $it) { $s = $it['status'] ?: 'Quote'; $st[$s] = ($st[$s] ?? 0) + 1; }
            $src = [['Item lines', count($list), 'int'], ['Suppliers', count(array_unique(array_filter(array_column($list, 'supplier_id')))), 'int'],
                ['Origin countries', count(array_unique(array_filter(array_map(fn ($it) => $this->c->originOf($it), $list)))), 'int'],
                ['Total pieces', $T('total_items'), 'int'], ['Total CBM', $T('cbm'), 'n3'], ['Goods cost', $T('item_cost'), 'm'], ['Freight', $T('freight'), 'm'], ['Delivery', $T('delivery'), 'm'],
                ['Landed total', $T('total'), 'm'], ['Average cost per piece', $avg($ids), 'm3'], ['Freight + delivery as % of landed total', $grand ? (($T('shipping') ?? 0) / $grand) : null, 'pct']];
            if ($prof) $src[] = ['Expected profit (lines with a sell price)', array_sum(array_map(fn ($id) => $R[$id]['profit'] * $R[$id]['qty'], $prof)), 'm'];
            foreach (\App\Http\Controllers\API\PurchasingApiController::ITEM_STATUS as $s) if (!empty($st[$s])) $src[] = ["Lines with status \"$s\"", $st[$s], 'int'];
            $rows = array_map(fn ($x) => ['measure' => $x[0], 'value' => $x[1], '_t' => $x[2]], $src);
        }
        return ['type' => $type, 'title' => self::REPORTS[$type], 'subtitle' => $this->filterText($f), 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $totals];
    }

    private static function cellTxt($v, ?string $t): string
    {
        if ($v === null || $v === '') return '';
        if (!$t || $t === 'text' || !is_numeric($v)) return (string) $v;
        if ($t === 'pct') return number_format($v * 100, 1) . '%';
        return number_format((float) $v, $t === 'int' ? 0 : (in_array($t, ['n3', 'm3'], true) ? 3 : 2));
    }

    /** Centre-crop a stored picture to the cell's aspect ratio so it fills the frame (temp jpg path). */
    private function cropped(?string $rel, float $aspect): ?string
    {
        if (!$rel) return null;
        $path = Storage::disk('public')->path($rel);
        if (!is_file($path)) return null;
        if (!function_exists('imagecreatefromstring')) return $path;
        $img = @imagecreatefromstring(file_get_contents($path));
        if (!$img) return null;
        [$w, $h] = [imagesx($img), imagesy($img)];
        [$sx, $sy, $sw, $sh] = [0, 0, $w, $h];
        if ($w / $h > $aspect) { $sw = (int) round($h * $aspect); $sx = (int) (($w - $sw) / 2); } else { $sh = (int) round($w / $aspect); $sy = (int) (($h - $sh) / 2); }
        $k = min(1, 600 / max($sw, $sh));
        $out = imagecreatetruecolor(max(1, (int) ($sw * $k)), max(1, (int) ($sh * $k)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, $sx, $sy, imagesx($out), imagesy($out), $sw, $sh);
        $tmp = tempnam(sys_get_temp_dir(), 'pur') . '.jpg';
        imagejpeg($out, $tmp, 85);
        imagedestroy($img); imagedestroy($out);
        return $this->tmp[] = $tmp;
    }

    private function sendXlsx(Spreadsheet $book, string $name)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'purx');
        (new Xlsx($book))->save($tmp);
        return response()->download($tmp, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    public function download(array $rep, string $format, bool $pictures)
    {
        $base = 'Report_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $rep['title']), '_') . '_' . now()->toDateString();
        $pics = $pictures && $rep['type'] === 'items';
        $tOf = fn ($c, $r) => ($c['t'] ?? null) === 'row' ? $r['_t'] : ($c['t'] ?? null);

        if ($format === 'csv') {
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, [$rep['title']]); fputcsv($fh, [$rep['subtitle']]); fputcsv($fh, []);
            fputcsv($fh, array_map(fn ($c) => $c['h'] . (($c['t'] ?? '') === 'pct' ? ' %' : ''), $rep['columns']));
            $raw = fn ($v, $t) => $v === null ? '' : (is_numeric($v) && !is_string($v) ? ($t === 'pct' ? round($v * 100, 1) : round($v, 4)) : $v);
            foreach ($rep['rows'] as $r) fputcsv($fh, array_map(fn ($c) => $raw($r[$c['k']] ?? null, $tOf($c, $r)), $rep['columns']));
            if ($rep['totals']) fputcsv($fh, array_map(fn ($c) => $raw($rep['totals'][$c['k']] ?? null, $c['t'] ?? null), $rep['columns']));
            rewind($fh);
            $csv = stream_get_contents($fh);
            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$base.csv\""]);
        }

        if ($format === 'pdf') {
            $cur = $pics ? '<th style="width:46pt">Picture</th>' : '';
            $h = '<style>body{font-family:dejavusans;font-size:8pt;color:#111}h1{font-size:15pt;margin:0}p.s{color:#666;margin:2pt 0 10pt}table{border-collapse:collapse;width:100%}th{background:#1f1f1f;color:#fff;text-align:left;padding:4pt}td{border-bottom:0.5pt solid #ddd;padding:3pt 4pt}td.n,th.n{text-align:right}tr.t td{background:#ededed;font-weight:bold}</style>';
            $h .= '<h1>Lebazone · ' . e($rep['title']) . '</h1><p class="s">' . e($rep['subtitle']) . ' · Generated ' . now()->format('Y-m-d H:i') . '</p><table><thead><tr>' . $cur;
            foreach ($rep['columns'] as $c) $h .= '<th class="' . (!empty($c['t']) ? 'n' : '') . '">' . e($c['h']) . '</th>';
            $h .= '</tr></thead><tbody>';
            foreach ($rep['rows'] as $r) {
                $h .= '<tr>';
                if ($pics) { $img = $this->cropped($r['_pic'] ?? null, 46 / 40); $h .= '<td>' . ($img ? '<img src="' . $img . '" style="width:44pt;height:38pt">' : '') . '</td>'; }
                foreach ($rep['columns'] as $c) { $t = $tOf($c, $r); $h .= '<td class="' . ($t ? 'n' : '') . '">' . e(self::cellTxt($r[$c['k']] ?? null, $t)) . '</td>'; }
                $h .= '</tr>';
            }
            if ($rep['totals']) {
                $h .= '<tr class="t">' . ($pics ? '<td></td>' : '');
                foreach ($rep['columns'] as $c) $h .= '<td class="' . (!empty($c['t']) ? 'n' : '') . '">' . e(self::cellTxt($rep['totals'][$c['k']] ?? null, $c['t'] ?? null)) . '</td>';
                $h .= '</tr>';
            }
            $h .= '</tbody></table>';
            $tmpDir = storage_path('app/mpdf'); @mkdir($tmpDir, 0777, true);
            $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => count($rep['columns']) > 6 ? 'A4-L' : 'A4', 'tempDir' => $tmpDir, 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 12]);
            $pdf->SetFooter('{PAGENO} / {nbpg}');
            $pdf->WriteHTML($h);
            return response($pdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"$base.pdf\""]);
        }

        // xlsx
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle(mb_substr($rep['title'], 0, 31));
        $ws->setCellValue('A1', $rep['title'])->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', $rep['subtitle'])->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('666666');
        $ws->setCellValue('A3', 'Generated ' . now()->format('Y-m-d H:i'));
        $hr = 5; $off = $pics ? 1 : 0;
        $cols = $pics ? array_merge([['h' => 'Picture']], $rep['columns']) : $rep['columns'];
        foreach ($cols as $i => $c) {
            $cell = $ws->getCell([$i + 1, $hr]);
            $cell->setValue($c['h']);
            $st = $cell->getStyle();
            $st->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F1F1F');
            $st->getAlignment()->setWrapText(true);
            $ws->getColumnDimensionByColumn($i + 1)->setWidth(($c['h'] === 'Picture') ? 14 : (!empty($c['t']) ? 13 : 22));
        }
        foreach ($rep['rows'] as $ri => $r) {
            $row = $hr + 1 + $ri;
            foreach ($rep['columns'] as $j => $c) {
                $v = $r[$c['k']] ?? null; $t = $tOf($c, $r);
                $ws->setCellValue([$j + 1 + $off, $row], $v === '' ? null : $v);
                if ($t && isset(self::NUMFMT[$t])) $ws->getStyle([$j + 1 + $off, $row])->getNumberFormat()->setFormatCode(self::NUMFMT[$t]);
            }
            if ($pics) {
                $ws->getRowDimension($row)->setRowHeight(52);
                if ($img = $this->cropped($r['_pic'] ?? null, 103 / 69)) {
                    $d = new Drawing(); $d->setPath($img); $d->setCoordinates('A' . $row); $d->setOffsetX(1); $d->setOffsetY(1);
                    $d->setResizeProportional(false); $d->setWidth(101); $d->setHeight(67); $d->setWorksheet($ws);
                }
            }
        }
        if ($rep['totals']) {
            $row = $hr + 1 + count($rep['rows']);
            foreach ($rep['columns'] as $j => $c) {
                $v = $rep['totals'][$c['k']] ?? null;
                $ws->setCellValue([$j + 1 + $off, $row], $v);
                if (!empty($c['t']) && isset(self::NUMFMT[$c['t']])) $ws->getStyle([$j + 1 + $off, $row])->getNumberFormat()->setFormatCode(self::NUMFMT[$c['t']]);
            }
            $ws->getStyle([1, $row, count($cols), $row])->getFont()->setBold(true);
            $ws->getStyle([1, $row, count($cols), $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDEDED');
        }
        $ws->freezePane('A' . ($hr + 1));
        return $this->sendXlsx($book, "$base.xlsx");
    }

    /** Full workbook: Orders (live formulas + pictures), Shipments, Suppliers, Freight rates. */
    public function fullWorkbook()
    {
        $c = $this->c;
        $R = $c->computeAll();
        $items = $c->items;
        uasort($items, fn ($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('Orders');
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $ws->setCellValue('A1', 'CBM PRICE (per 1 CBM)')->getStyle('A1')->getFont()->setBold(true);
        $ws->setCellValue('B1', $c->defaultRate());
        $ws->getStyle('B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $ws->getStyle('B1')->getFont()->setBold(true)->getColor()->setRGB('0000FF');
        $ws->setCellValue('C1', '← default freight rate; rows with another rate show it in CBM PRICE USED');
        $ws->setCellValue('A2', 'Exported ' . now()->toDateString() . ' · amounts in ' . $c->setting('currency', 'USD') . '. Yellow = inputs. Delivery is per whole shipment/order, already split per row.');
        $cols = [['ITEM NAME', 24], ['PICTURE', 20], ['VARIANT (Color / Size / etc.)', 22], ['SKU', 12], ['SUPPLIER', 20], ['SHIPMENT', 18], ['STATUS', 11], ['BOX LENGTH (cm)', 10], ['BOX WIDTH (cm)', 10], ['BOX HEIGHT (cm)', 10],
            ['NO. OF BOXES', 9], ['ITEMS PER BOX', 9], ['TOTAL ITEMS', 10], ['TOTAL CBM', 11], ['CBM PRICE USED', 10], ['ITEM COST (per item)', 11], ['TOTAL ITEM COST', 13], ['FREIGHT COST (CBM × CBM PRICE)', 14],
            ['DELIVERY COST (share of shipment)', 14], ['SHIPPING COST (DELIVERY + FREIGHT)', 15], ['TOTAL COST', 14], ['COST PER ITEM (after everything)', 14], ['SELL PRICE', 10], ['PROFIT PER ITEM', 11], ['MARGIN', 9],
            ['ORIGIN COUNTRY', 14], ['FREIGHT RATE SOURCE', 26], ['ITEM LENGTH (cm)', 10], ['ITEM WIDTH (cm)', 10], ['ITEM HEIGHT (cm)', 10], ['BOX SIZE SOURCE', 18], ['LEBAZONE SKU', 14]];
        foreach ($cols as $i => [$h, $w]) {
            $ws->setCellValue([$i + 1, 4], $h);
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
        $hs = $ws->getStyle([1, 4, count($cols), 4]);
        $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F3864');
        $hs->getAlignment()->setWrapText(true)->setHorizontal('center')->setVertical('center');
        $ws->getRowDimension(4)->setRowHeight(42);
        $fmt = [8 => '#,##0.0', 9 => '#,##0.0', 10 => '#,##0.0', 11 => '#,##0.00', 12 => '#,##0', 13 => '#,##0', 14 => '0.0000', 15 => '#,##0.00', 16 => '#,##0.000', 17 => '#,##0.00', 18 => '#,##0.00',
            19 => '#,##0.00', 20 => '#,##0.00', 21 => '#,##0.00', 22 => '#,##0.000', 23 => '#,##0.00', 24 => '#,##0.000', 25 => '0.0%'];
        $inputs = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 16, 19, 23];
        $skus = \App\Models\ProductVariant::whereIn('id', array_filter(array_column($items, 'product_variant_id')))->pluck('sku', 'id');
        $row = 5;
        foreach ($items as $id => $it) {
            $r = $R[$id];
            $set = fn ($col, $v) => $ws->setCellValue([$col, $row], $v);
            $set(1, $it['name']); $set(3, (string) $it['variants']); $set(4, (string) $it['sku']);
            $set(5, $c->suppliers[$it['supplier_id']]['name'] ?? ''); $set(6, $c->shipments[$it['shipment_id']]['name'] ?? ''); $set(7, $it['status'] ?: 'Quote');
            if ($r['cbm_estimated']) { $set(8, $r['est']['L']); $set(9, $r['est']['W']); $set(10, $r['est']['H']); }
            else { $set(8, Calc::num($it['box_l'])); $set(9, Calc::num($it['box_w'])); $set(10, Calc::num($it['box_h'])); }
            $set(11, $r['per_used'] !== null || $r['cbm'] !== null ? $r['boxes_used'] : Calc::num($it['boxes']));
            $set(12, $r['per_used'] ?? Calc::num($it['per_box']));
            $set(13, "=IF(L$row=\"\",\"\",L$row*IF(K$row=\"\",1,K$row))");
            $set(14, "=IF(COUNT(H$row:J$row)=3,H$row*I$row*J$row/1000000*IF(K$row=\"\",1,K$row),\"\")");
            $set(15, $r['rate_src'] === 'Default' ? '=$B$1' : $r['rate']);
            $set(16, Calc::num($it['unit_cost']));
            $set(17, "=IF(P$row=\"\",\"\",P$row*IF(M$row=\"\",1,M$row))");
            $set(18, "=IF(N$row=\"\",\"\",N$row*O$row)");
            $set(19, $r['delivery'] === null ? null : round($r['delivery'], 4));
            $set(20, "=IF(COUNT(R$row,S$row)=0,\"\",SUM(R$row,S$row))");
            $set(21, "=IF(COUNT(Q$row,T$row)=0,\"\",SUM(Q$row,T$row))");
            $set(22, "=IF(U$row=\"\",\"\",IF(M$row=0,\"\",U$row/IF(M$row=\"\",1,M$row)))");
            $set(23, Calc::num($it['sell_price']));
            $set(24, "=IF(OR(W$row=\"\",V$row=\"\"),\"\",W$row-V$row)");
            $set(25, "=IF(OR(X$row=\"\",W$row=0),\"\",X$row/W$row)");
            $set(26, $r['origin']); $set(27, $r['rate_src']);
            $set(28, Calc::num($it['item_l'])); $set(29, Calc::num($it['item_w'])); $set(30, Calc::num($it['item_h']));
            $set(31, $r['cbm_estimated'] ? 'Estimated from item size' : ($r['has_box'] ? 'Real box' : ''));
            $set(32, $it['product_variant_id'] ? ($skus[$it['product_variant_id']] ?? '') : '');
            foreach ($fmt as $col => $f) $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode($f);
            foreach (range(1, count($cols)) as $col) {
                $s = $ws->getStyle([$col, $row]);
                $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(in_array($col, $inputs, true) ? 'FFF2CC' : 'F2F2F2');
                if (in_array($col, $inputs, true)) $s->getFont()->getColor()->setRGB('0000FF');
                $s->getAlignment()->setVertical('center')->setWrapText($col <= 7 || $col === 31);
            }
            if ($r['cbm_estimated']) $ws->getStyle([8, $row, 10, $row])->getFont()->setItalic(true)->getColor()->setRGB('808080');
            $ws->getStyle([21, $row, 22, $row])->getFont()->setBold(true);
            $ws->getRowDimension($row)->setRowHeight(75);
            if ($img = $this->cropped($it['picture'], 145 / 100)) {
                $d = new Drawing(); $d->setPath($img); $d->setCoordinates('B' . $row); $d->setOffsetX(1); $d->setOffsetY(1);
                $d->setResizeProportional(false); $d->setWidth(143); $d->setHeight(98); $d->setWorksheet($ws);
            }
            $row++;
        }
        if ($row > 5) {
            $last = $row - 1;
            $ws->setCellValue([1, $row], 'TOTALS');
            foreach ([13 => 'M', 14 => 'N', 17 => 'Q', 18 => 'R', 19 => 'S', 20 => 'T', 21 => 'U'] as $col => $L) {
                $ws->setCellValue([$col, $row], "=SUM({$L}5:{$L}{$last})");
                $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode($fmt[$col]);
            }
            $ws->getStyle([1, $row, count($cols), $row])->getFont()->setBold(true);
            $ws->getStyle([1, $row, count($cols), $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
        }
        $ws->freezePane('B5');

        $sheet = function (string $title, array $head, array $rows) use ($book) {
            $s = $book->createSheet(); $s->setTitle($title);
            foreach ($head as $i => [$h, $w]) { $s->setCellValue([$i + 1, 1], $h); $s->getColumnDimensionByColumn($i + 1)->setWidth($w); }
            $st = $s->getStyle([1, 1, count($head), 1]);
            $st->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F1F1F');
            foreach ($rows as $ri => $r) foreach (array_values($r) as $ci => $v) $s->setCellValue([$ci + 1, $ri + 2], $v);
            $s->freezePane('A2');
        };
        $ship = [];
        foreach ($c->shipments as $sh) {
            $ids = array_keys(array_filter($c->items, fn ($i) => (int) $i['shipment_id'] === (int) $sh['id']));
            $sr = $c->shipRate($sh);
            $ship[] = [$sh['name'], $sh['status'], $sh['ship_date'], $sh['eta'], $sh['forwarder'], $sh['tracking'], $sr ? $sr['rate'] : 'per item', (float) $sh['delivery_cost'],
                Calc::ALLOC[$sh['allocation']] ?? $sh['allocation'], count($ids), Calc::sumOf($ids, $R, 'total_items'), Calc::sumOf($ids, $R, 'cbm'), Calc::sumOf($ids, $R, 'total'), $sh['notes']];
        }
        $sheet('Shipments', [['Shipment', 26], ['Status', 12], ['Ship date', 12], ['ETA', 12], ['Forwarder', 18], ['Tracking', 18], ['CBM price', 11], ['Delivery cost (whole)', 14], ['Split by', 18], ['Item lines', 10], ['Pieces', 10], ['CBM', 10], ['Landed total', 14], ['Notes', 30]], $ship);
        $sup = [];
        foreach ($c->suppliers as $s) {
            $ids = array_keys(array_filter($c->items, fn ($i) => (int) $i['supplier_id'] === (int) $s['id']));
            $sup[] = [$s['name'], $s['company'], $s['platform'], $s['contact'], $s['phone'], $s['whatsapp'], $s['wechat'], $s['email'], $s['website'], $s['address'], $s['city'], $s['country'],
                $s['payment_terms'], $s['lead_time'], $s['moq'], $s['bank'], $s['rating'], count($ids), Calc::sumOf($ids, $R, 'item_cost'), $s['notes']];
        }
        $sheet('Suppliers', [['Supplier', 24], ['Company', 22], ['Platform', 14], ['Contact', 18], ['Phone', 16], ['WhatsApp', 16], ['WeChat', 14], ['Email', 24], ['Website', 30], ['Address', 30], ['City', 14], ['Origin country', 14],
            ['Payment terms', 24], ['Lead time (days)', 10], ['MOQ', 10], ['Bank / payment', 24], ['Rating', 8], ['Items', 8], ['Goods cost', 14], ['Notes', 30]], $sup);
        $rates = [['Default rate', '', '', $c->defaultRate(), '', '', '', 'Used when no other rate applies']];
        foreach ($c->rates as $r) {
            $hist = json_decode((string) $r['history'], true) ?: [];
            $rates[] = [$c->rateLabel($r), $r['country'], $r['mode'], (float) $r['price'], (int) $r['auto'] ? 'Yes' : 'No', substr((string) $r['updated_at'], 0, 10),
                implode('; ', array_map(fn ($h) => "{$h['price']} until {$h['until']}", $hist)), $r['notes']];
        }
        $sheet('Freight rates', [['Rate', 30], ['Origin country', 16], ['Method', 12], ['Price per CBM', 13], ['Automatic for country', 12], ['Updated', 12], ['Previous prices', 40], ['Notes', 30]], $rates);
        $book->setActiveSheetIndex(0);
        return $this->sendXlsx($book, 'Lebazone_Purchasing_' . now()->toDateString() . '.xlsx');
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
iniciarSesionESG();

try {
    exigirSuperAdmin();
} catch (Throwable $e) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$equipos = db()->query('
    SELECT hostname, username, domain_name, os_name, os_version, os_build,
           ip_origen, cpu_json, memory_json, disks_json, antivirus_json, last_seen
    FROM agentes
    ORDER BY hostname ASC, id ASC
')->fetchAll();

function jsonArray(mixed $value): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function firstValue(array $item, array $keys): string {
    foreach ($keys as $key) {
        if (isset($item[$key]) && trim((string)$item[$key]) !== '') {
            return trim((string)$item[$key]);
        }
    }
    return '';
}

function textValue(mixed $value): string {
    if ($value === null) return '';
    if (is_scalar($value)) return trim((string)$value);
    return trim((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function cpuResumen(mixed $value): string {
    $items = jsonArray($value);
    if (!$items) return '-';
    $parts = [];
    foreach (array_slice($items, 0, 2) as $cpu) {
        if (!is_array($cpu)) continue;
        $name = firstValue($cpu, ['Name','name','Caption','caption']);
        $cores = firstValue($cpu, ['NumberOfCores','number_of_cores','cores','CoreCount']);
        $part = $name !== '' ? $name : '';
        if ($cores !== '') $part .= ($part !== '' ? ' - ' : '') . $cores . ' núcleos';
        if ($part !== '') $parts[] = $part;
    }
    return $parts ? implode(' | ', $parts) : '-';
}

function ramResumen(mixed $value): string {
    $items = jsonArray($value);
    if (!$items) return '-';
    $total = 0.0;
    foreach ($items as $ram) {
        if (!is_array($ram)) continue;
        $capacity = $ram['Capacity'] ?? $ram['capacity'] ?? $ram['CapacityBytes'] ?? $ram['capacity_bytes'] ?? 0;
        if (is_numeric($capacity)) $total += (float)$capacity;
    }
    if ($total <= 0) return '-';
    $gb = $total / 1073741824;
    $label = $gb >= 100 ? number_format($gb, 0, ',', '.') : number_format($gb, 1, ',', '.');
    return $label . ' GB';
}

function almacenamientoResumen(mixed $value): string {
    $items = jsonArray($value);
    if (!$items) return '-';
    $tipos = [];
    foreach ($items as $disk) {
        if (!is_array($disk)) continue;
        $raw = strtolower(implode(' ', array_map('textValue', [
            $disk['MediaType'] ?? '', $disk['media_type'] ?? '',
            $disk['InterfaceType'] ?? '', $disk['interface_type'] ?? '',
            $disk['Model'] ?? '', $disk['model'] ?? '',
            $disk['Name'] ?? '', $disk['name'] ?? '',
            $disk['BusType'] ?? '', $disk['bus_type'] ?? ''
        ])));

        $tipo = '';
        if (str_contains($raw, 'nvme')) $tipo = 'NVMe';
        elseif (str_contains($raw, 'ssd') || str_contains($raw, 'solid state')) $tipo = 'SSD';
        elseif (str_contains($raw, 'hdd') || str_contains($raw, 'hard disk') || str_contains($raw, 'sata') || str_contains($raw, 'scsi')) $tipo = 'HDD';
        elseif ($raw !== '') $tipo = 'Desconocido';

        if ($tipo !== '') $tipos[$tipo] = true;
    }
    return $tipos ? implode(' / ', array_keys($tipos)) : '-';
}

function antivirusResumen(mixed $value): string {
    $items = jsonArray($value);
    if (!$items) return '-';
    $names = [];
    foreach ($items as $av) {
        if (is_string($av)) {
            if (trim($av) !== '') $names[] = trim($av);
            continue;
        }
        if (!is_array($av)) continue;
        $name = firstValue($av, ['displayName','DisplayName','name','Name','productName','ProductName','caption','Caption']);
        if ($name === '') {
            $name = firstValue($av, ['path','Path','publisher','Publisher']);
        }
        if ($name !== '') $names[] = $name;
    }
    $names = array_values(array_unique($names));
    return $names ? implode(' / ', $names) : '-';
}

function sistemaResumen(array $e): string {
    $parts = [];
    if (!empty($e['os_name'])) $parts[] = trim((string)$e['os_name']);
    if (!empty($e['os_version'])) $parts[] = trim((string)$e['os_version']);
    if (!empty($e['os_build'])) $parts[] = 'Build ' . trim((string)$e['os_build']);
    return $parts ? implode(' ', $parts) : '-';
}

$rows = [];
foreach ($equipos as $e) {
    $rows[] = [
        (string)($e['hostname'] ?: '-'),
        (string)($e['username'] ?: '-'),
        (string)($e['domain_name'] ?: '-'),
        sistemaResumen($e),
        cpuResumen($e['cpu_json']) . ' / ' . ramResumen($e['memory_json']),
        (string)($e['ip_origen'] ?: '-'),
        almacenamientoResumen($e['disks_json']),
        (string)($e['last_seen'] ?: '-'),
        antivirusResumen($e['antivirus_json']),
    ];
}

// PDF mínimo sin dependencias externas, pensado para texto/tablas.
class SimplePDF {
    private array $objects = [];
    private int $pagesObj = 0;
    private array $pageObjects = [];
    private int $fontRef = 0;

    public function __construct() {
        $this->fontRef = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $this->pagesObj = $this->addObject('');
    }

    private function addObject(string $body): int {
        $this->objects[] = $body;
        return count($this->objects);
    }

    private function streamObject(string $stream): int {
        return $this->addObject("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
    }

    private function esc(string $s): string {
        $s = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) ?: $s;
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
    }

    public function addPage(array $lines): void {
        $w = 842; $h = 595; // A4 landscape
        $y = 560;
        $content = "BT\n/F1 9 Tf\n";
        foreach ($lines as $line) {
            $text = $line['text'];
            $x = $line['x'];
            $size = $line['size'] ?? 9;
            $content .= sprintf("/F1 %d Tf\n1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $size, $x, $line['y'] ?? $y, $this->esc($text));
            if (!isset($line['y'])) $y -= ($line['leading'] ?? 12);
        }
        $content .= "ET\n";
        $stream = $this->streamObject($content);
        $page = $this->addObject(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $this->pagesObj, $w, $h, $this->fontRef, $stream));
        $this->pageObjects[] = $page;
    }

    public function output(): void {
        $kids = implode(' ', array_map(fn($n) => $n . ' 0 R', $this->pageObjects));
        $this->objects[$this->pagesObj - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count($this->pageObjects));
        $catalog = $this->addObject(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $this->pagesObj));

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($this->objects as $i => $body) {
            $offsets[$i+1] = strlen($pdf);
            $pdf .= ($i+1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects)+1) . "\n0000000000 65535 f \n";
        for ($i=1; $i<=count($this->objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($this->objects)+1) . " /Root " . $catalog . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="reporte_equipos_esg.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $pdf;
        exit;
    }
}

function wrapText(string $text, int $maxChars): array {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') return ['-'];
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = []; $current = '';
    foreach ($words as $word) {
        if ($current === '') { $current = $word; continue; }
        if ((function_exists('mb_strlen') ? mb_strlen($current . ' ' . $word) : strlen($current . ' ' . $word)) <= $maxChars) $current .= ' ' . $word;
        else { $lines[] = $current; $current = $word; }
    }
    if ($current !== '') $lines[] = $current;
    return $lines ?: ['-'];
}

$headers = ['PC','Usuario','Dominio','Sistema','CPU / RAM','IP','Tipo de almacenamiento','Último reporte','Antivirus'];
$widths = [72,70,75,105,100,75,105,80,110];
$xs = [24];
for ($i=1; $i<count($widths); $i++) $xs[$i] = $xs[$i-1] + $widths[$i-1];

$pdf = new SimplePDF();
$pageLines = [];
$y = 560;
$maxRowsPerPage = 36;
$rowCount = 0;

$renderHeader = function() use (&$pageLines, &$y, $headers, $xs, $widths) {
    $pageLines[] = ['text'=>'Reporte de equipos ESG', 'x'=>24, 'y'=>568, 'size'=>14];
    $y = 545;
    foreach ($headers as $i=>$h) {
        $lines = wrapText($h, max(10, (int)($widths[$i]/5.4)));
        foreach ($lines as $j=>$line) $pageLines[]=['text'=>$line,'x'=>$xs[$i],'y'=>$y-$j*9,'size'=>8];
    }
    $y -= 22;
};

$renderHeader();
foreach ($rows as $row) {
    $wrapped=[]; $maxLines=1;
    foreach ($row as $i=>$value) {
        $lines=wrapText($value, max(10, (int)($widths[$i]/5.6)));
        $wrapped[$i]=$lines; $maxLines=max($maxLines,count($lines));
    }
    $rowHeight = max(14, $maxLines*9+4);
    if ($rowCount >= $maxRowsPerPage || $y - $rowHeight < 25) {
        $pdf->addPage($pageLines);
        $pageLines=[]; $rowCount=0; $renderHeader();
    }
    foreach ($wrapped as $i=>$lines) foreach ($lines as $j=>$line) $pageLines[]=['text'=>$line,'x'=>$xs[$i],'y'=>$y-$j*9,'size'=>7];
    $y -= $rowHeight; $rowCount++;
}

if (!$rows) {
    $pageLines[]=['text'=>'No hay equipos registrados.', 'x'=>24, 'y'=>$y, 'size'=>9];
}
$pdf->addPage($pageLines);
$pdf->output();

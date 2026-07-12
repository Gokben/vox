param(
    [Parameter(Mandatory=$true)][string]$ExcelPath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)

Add-Type -AssemblyName System.IO.Compression.FileSystem

function SqlText([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return 'NULL' }
    return "'" + $Value.Replace("'", "''").Replace("\", "\\") + "'"
}

function SqlDate([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return 'NULL' }
    $date = [datetime]::MinValue
    if ([datetime]::TryParseExact($Value.Trim(), 'dd/MM/yyyy', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::None, [ref]$date)) {
        return "'" + $date.ToString('yyyy-MM-dd') + "'"
    }
    if ($Value -match '^\d+(\.\d+)?$') {
        return "'" + [datetime]::FromOADate([double]$Value).ToString('yyyy-MM-dd') + "'"
    }
    throw "Geçersiz tarih: $Value"
}

function SqlDateTime([string]$DateValue, [string]$TimeValue) {
    if ([string]::IsNullOrWhiteSpace($DateValue)) { return 'NULL' }
    $dateSql = SqlDate $DateValue
    $date = $dateSql.Trim("'")
    $time = if ([string]::IsNullOrWhiteSpace($TimeValue)) { '00:00:00' } else { $TimeValue.Trim() }
    if ($time -match '^\d{1,2}:\d{2}$') { $time += ':00' }
    return "'$date $time'"
}

$zip = [IO.Compression.ZipFile]::OpenRead($ExcelPath)
try {
    $entry = $zip.GetEntry('xl/sharedStrings.xml')
    $reader = New-Object IO.StreamReader($entry.Open())
    [xml]$sharedXml = $reader.ReadToEnd(); $reader.Close()
    $shared = @($sharedXml.SelectNodes('//*[local-name()="si"]') | ForEach-Object { $_.InnerText })

    $entry = $zip.GetEntry('xl/worksheets/sheet1.xml')
    $reader = New-Object IO.StreamReader($entry.Open())
    [xml]$sheetXml = $reader.ReadToEnd(); $reader.Close()
    $rows = @($sheetXml.SelectNodes('//*[local-name()="sheetData"]/*[local-name()="row"]'))

    $sql = New-Object Collections.Generic.List[string]
    $sql.Add('-- Excel found items import - 913 records')
    $sql.Add('SET NAMES utf8mb4;')
    $sql.Add('SET time_zone = ''+03:00'';')
    $sql.Add('START TRANSACTION;')

    $order = 0
    foreach ($row in ($rows | Select-Object -Skip 1)) {
        $order++
        $cells = @{}
        foreach ($cell in $row.SelectNodes('./*[local-name()="c"]')) {
            $column = $cell.r -replace '\d',''
            $valueNode = $cell.SelectSingleNode('./*[local-name()="v"]')
            $value = if ($valueNode) { $valueNode.InnerText } else { '' }
            if ($cell.t -eq 's' -and $value -ne '') { $value = $shared[[int]$value] }
            $cells[$column] = $value.Trim()
        }

        $finder = $cells['K']; $department = ''; $foundBy = $finder
        if ($finder -match '^(.*?)\s+-\s+(.*)$') { $department = $Matches[1].Trim(); $foundBy = $Matches[2].Trim() }
        $recordedBy = if ($cells['Z']) { $cells['Z'] } else { 'Excel Import' }
        $quantity = if ($cells['F']) { [int][double]$cells['F'] } else { 1 }
        $createdAt = SqlDateTime $cells['W'] $cells['X']
        $deliveredAt = SqlDateTime $cells['Q'] $cells['R']

        $values = @(
            (SqlText $cells['A']), (SqlText $cells['C']), (SqlText $cells['B']), $order,
            (SqlDate $cells['G']), (SqlText $cells['J']), (SqlText $department), (SqlText $foundBy),
            (SqlText $cells['D']), (SqlText $cells['E']), (SqlText $cells['L']), (SqlText $cells['M']),
            $quantity, (SqlText $cells['O']), (SqlText $cells['N']), (SqlText $cells['P']),
            $deliveredAt, (SqlText $cells['T']), (SqlText $cells['U']), (SqlText $cells['V']),
            (SqlText $recordedBy), $createdAt
        ) -join ','
        $sql.Add("INSERT INTO items (item_no,serial_no,related_items,import_order,found_at,location,found_department,found_by,category,name,brand,color,quantity,details,storage_location,status,delivered_at,delivery_method,delivered_by,delivery_form_no,recorded_by,created_at) VALUES ($values);")
    }
    $sql.Add('COMMIT;')
    [IO.File]::WriteAllLines($OutputPath, $sql, (New-Object Text.UTF8Encoding($false)))
    Write-Output "SQL oluşturuldu: $order kayıt"
}
finally { $zip.Dispose() }

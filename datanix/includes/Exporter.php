<?php
declare(strict_types=1);

final class DatanixExporter
{
    private static function safeCell(mixed $value): string
    {
        $text=(string)($value??'');
        $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$text)??'';
        if($text!==''&&!is_numeric($text)&&preg_match('/^[=+@\-\t\r]/u',$text))$text="'".$text;
        return $text;
    }

    public static function csv(array $headers,array $rows,string $filename='datanix-export.csv'): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','-',basename($filename)).'"');
        header('Cache-Control: no-store');
        $out=fopen('php://output','wb');
        if(!$out)throw new RuntimeException('CSV output tidak dapat dibuka.');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,array_map([self::class,'safeCell'],$headers));
        foreach($rows as $row){$values=array_is_list($row)?$row:array_map(static fn($header)=>$row[$header]??'',$headers);fputcsv($out,array_map([self::class,'safeCell'],$values));}
        fclose($out);exit;
    }

    private static function columnName(int $number): string
    {
        $name='';while($number>0){$remainder=($number-1)%26;$name=chr(65+$remainder).$name;$number=intdiv($number-1,26);}return $name;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars(self::safeCell($value),ENT_XML1|ENT_QUOTES,'UTF-8');
    }

    private static function cell(int $row,int $column,mixed $value,bool $header=false): string
    {
        $ref=self::columnName($column).$row;$style=$header?' s="1"':'';
        if(is_int($value)||is_float($value))return '<c r="'.$ref.'" t="n"'.$style.'><v>'.(is_finite((float)$value)?(string)$value:'0').'</v></c>';
        return '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::xml((string)($value??'')).'</t></is></c>';
    }

    public static function xlsx(array $headers,array $rows,string $filename='datanix-export.xlsx'): never
    {
        if(count($headers)>100||count($rows)>10000)throw new RuntimeException('Ekspor XLSX dibatasi hingga 100 kolom dan 10.000 baris.');
        $tmp=tempnam(sys_get_temp_dir(),'dnx');if($tmp===false)throw new RuntimeException('File sementara tidak tersedia.');$zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){@unlink($tmp);throw new RuntimeException('Workbook XLSX tidak dapat dibuat.');}
        try{
            $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
            $zip->addFromString('[Content_Types].xml',$types);
            $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datanix" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF4E5BFF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
            $zip->addFromString('xl/styles.xml',$styles);
            $maxRows=count($rows)+1;$maxCols=max(1,count($headers));$last=self::columnName($maxCols).$maxRows;$sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';
            foreach(array_values($headers) as $index=>$header)$sheet.='<col min="'.($index+1).'" max="'.($index+1).'" width="'.($index===0?28:($index<3?20:16)).'" customWidth="1"/>';
            $sheet.='</cols><sheetData><row r="1" ht="24" customHeight="1">';foreach(array_values($headers) as $index=>$header)$sheet.=self::cell(1,$index+1,$header,true);$sheet.='</row>';
            foreach($rows as $rowIndex=>$row){$rowNumber=$rowIndex+2;$values=array_is_list($row)?$row:array_map(static fn($header)=>$row[$header]??'',$headers);$sheet.='<row r="'.$rowNumber.'">';foreach(array_values($values) as $columnIndex=>$value)$sheet.=self::cell($rowNumber,$columnIndex+1,$value);$sheet.='</row>';}
            $sheet.='</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
            if(!$zip->close())throw new RuntimeException('Workbook gagal disimpan.');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','-',basename($filename)).'"');header('Content-Length: '.filesize($tmp));header('Cache-Control: no-store');readfile($tmp);
        }finally{@unlink($tmp);}
        exit;
    }
}

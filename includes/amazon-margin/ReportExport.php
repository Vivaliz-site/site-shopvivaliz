<?php
declare(strict_types=1);

/**
 * Self-contained, dependency-free PDF/XLSX export of an authenticated audit.
 * No caller-supplied formula is emitted to spreadsheets.
 */
final class SvAmazonMarginExport
{
    /** @return array<string,string|int|float|null> */
    private static function values(array $row): array
    {
        $margin = $row['margin_weighted_bps'] ?? null;
        $reference=is_array($row['prior_cost_reference']??null) ? $row['prior_cost_reference'] : [];
        $status = $row['amazon_status'] ?? [];
        $buyable = is_array($status) && in_array('BUYABLE',$status,true);
        $reason = match((string)($row['status'] ?? '')) {
            'unmapped_erp' => 'Produto vinculado no ERP nao identificado',
            'ambiguous_erp' => 'Produto ERP ambiguo',
            'erp_or_catalog_read_failed' => 'Falha consulta ERP/catalogo',
            'erp_identity_mismatch' => 'EAN/SKU divergentes',
            'price_or_cost_missing' => 'Preco efetivo ou custo ausente',
            'kit_cost_unverified' => 'Custo do kit nao conciliado: conferir quantidade, composicao e divergencia de custos',
            'no_confirmed_sales' => 'Sem vendas conciliadas no periodo',
            'partial' => 'Conciliacao financeira parcial',
            'confirmed' => 'Conciliado',
            default => 'Aguardando verificacao',
        };
        return [
            'SKU Amazon'=>(string)($row['seller_sku'] ?? ''),
            'Produto ERP'=>(string)($row['erp_sku'] ?? ''),
            'ASIN'=>(string)($row['asin'] ?? ''),
            'EAN'=>(string)($row['ean'] ?? ''),
            'Ativo Amazon'=>$buyable?'Sim':'Nao',
            'Status Amazon'=>is_array($status)?implode(',',$status):'',
            'Canal'=>(string)($row['program'] ?? $row['fulfillment_channel'] ?? ''),
            'Preco Amazon (R$)'=>self::num($row['price'] ?? null),
            'Margem atual estimada (%)'=>isset($row['current_margin_bps']) ? round((int)$row['current_margin_bps']/100,2) : null,
            'Lucro atual por unidade (R$)'=>isset($row['current_profit_cents']) ? round((int)$row['current_profit_cents']/100,2) : null,
            'Comissao Amazon atual (R$)'=>isset($row['current_commission_cents']) ? round((int)$row['current_commission_cents']/100,2) : null,
            'Logistica atual (R$)'=>isset($row['current_logistics_cents']) ? round((int)$row['current_logistics_cents']/100,2) : null,
            'Imposto sobre preco atual (R$)'=>isset($row['current_tax_cents']) ? round((int)$row['current_tax_cents']/100,2) : null,
            'WMS FBA Onsite atual (R$)'=>isset($row['current_wms_cents']) ? round((int)$row['current_wms_cents']/100,2) : null,
            'Peso real (kg)'=>self::num($row['current_weight_kg'] ?? null),
            'Peso cubico (kg)'=>self::num($row['current_dimensional_kg'] ?? null),
            'Peso tarifavel (kg)'=>self::num($row['current_billable_kg'] ?? null),
            'Fonte medidas'=>(string)($row['current_package_source'] ?? ''),
            'Divergencia de medidas'=>!empty($row['current_package_conflict'])?'Sim':'Nao',
            'Situacao margem atual'=>(string)($row['current_margin_status'] ?? 'not_calculated'),
            'Fonte comissao atual'=>(string)($row['current_fee_source'] ?? ''),
            'Fonte tarifa logistica'=>(string)($row['current_tariff_source'] ?? ''),
            'Receita bruta media por unidade (R$)'=>self::num($row['realized_unit_price'] ?? null),
            'Receita bruta das unidades conciliadas (R$)'=>isset($row['realized_sale_gross_cents'])
                ? round((int)$row['realized_sale_gross_cents']/100,2):null,
            'Preco cadastro Amazon (R$)'=>self::num($row['listed_price'] ?? null),
            'Custo ERP por conjunto vendido (R$)'=>self::num($row['last_cost'] ?? null),
            'Custo base da peca no ERP (R$)'=>self::num($row['erp_base_cost'] ?? $row['last_cost'] ?? null),
            'Custo registrado de kit no ERP (R$)'=>(($row['erp_product_type']??'')==='K')
                ? self::num($row['erp_base_cost']??$row['last_cost']??null):null,
            'Custo calculado pela composicao do kit (R$)'=>self::num($row['erp_kit_bom_cost']??null),
            'Quantidade total de componentes do kit'=>isset($row['erp_kit_bom_total_units'])
                ? (int)$row['erp_kit_bom_total_units']:null,
            'Pecas por unidade vendida'=>isset($row['kit_units_per_listing']) ? (int)$row['kit_units_per_listing'] : null,
            'Multiplicador de custo'=>isset($row['kit_cost_multiplier']) ? (int)$row['kit_cost_multiplier'] : null,
            'Metodo do custo por conjunto'=>(string)($row['kit_cost_basis']??''),
            'Motivo da divergencia do kit'=>(string)($row['kit_cost_issue']??''),
            'Custo anterior verificado (R$)'=>self::num($reference['cost']??null),
            'Data custo anterior'=>(string)($reference['cost_date']??''),
            'Auditoria custo anterior'=>isset($reference['verified_run_id']) ? (int)$reference['verified_run_id'] : null,
            'SKU ERP anterior'=>(string)($reference['erp_sku']??''),
            'Fonte do vinculo'=>(string)($row['erp_match_method'] ?? ''),
            'Vinculo por UI Olist validado em'=>(string)($row['erp_ui_observed_at']??''),
            'Aba Anuncios do produto Olist validada em'=>(string)($row['erp_product_tab_observed_at']??''),
            'Vinculo confirmado pela aba Anuncios Olist'=>(!empty($row['erp_product_tab_verified'])?'Sim':'Nao'),
            'Reconciliacao pendente na aba de produto Olist'=>(!empty($row['erp_product_tab_crosscheck_pending'])?'Sim':'Nao'),
            'Candidato historico aba produto Olist'=>(string)($row['erp_historical_product_tab_candidate']['erp_sku']??''),
            'Produto sugerido pela UI Olist'=>(string)($row['erp_ui_candidate_sku']??''),
            'Divergencia UI Olist'=>(!empty($row['erp_ui_conflict'])?'Sim':'Nao'),
            'Motivo divergencia UI'=>(string)($row['erp_ui_conflict']??''),
            'Candidato UI historico'=>(string)($row['erp_historical_ui_candidate']['erp_sku']??''),
            'Integração ERP Amazon'=>(string)($row['erp_integration'] ?? ''),
            'Exportação Olist - data do vínculo'=>(string)($row['erp_export_observed_at']??''),
            'SKU ERP candidato histórico'=>(string)($row['erp_historical_candidate']['erp_sku']??''),
            'Origem do candidato histórico'=>(string)($row['erp_historical_candidate']['source']??''),
            'Candidato histórico não certificado'=>(!empty($row['erp_historical_candidate'])?'Sim':'Nao'),
            'Pedidos de vínculo comprovados'=>(int)($row['erp_order_evidence_count'] ?? 0),
            'GTIN Amazon difere do ERP'=>(!empty($row['erp_gtin_differs_from_amazon'])?'Sim':'Nao'),
            'Motivo do vinculo pendente'=>(string)($row['erp_mapping_reason'] ?? ''),

            'Unidades confirmadas'=>(int)($row['confirmed_units'] ?? 0),
            'Vendas pendentes'=>(int)($row['pending_sales'] ?? 0),
            'Margem ponderada (%)'=>$margin===null ? null : round($margin/100, 2),
            'Margem minima (%)'=>isset($row['margin_min_bps']) ? round($row['margin_min_bps']/100,2) : null,
            'Lucro por unidade vendida (R$)'=>(isset($row['margin_weighted_bps'],$row['profit_cents']) && (int)($row['confirmed_units']??0)>0)
                ? round(((int)$row['profit_cents'])/100/(int)$row['confirmed_units'],2):null,
            'Lucro acumulado do periodo (R$)'=>isset($row['margin_weighted_bps'],$row['profit_cents'])
                ? round(((int)$row['profit_cents'])/100,2) : null,
            'Data ultimo custo'=>(string)($row['last_cost_date'] ?? ''),
            'Situacao'=>(string)($row['status'] ?? 'unverified'),
            'Diagnostico'=>!empty($row['erp_ui_conflict']) ? 'Divergencia de produto ERP com UI Olist: custo/margem bloqueados' : $reason,
        ];
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /** @return list<array<string,string|int|float|null>> */
    public static function rows(array $snapshot, array $skus = []): array
    {
        $selected = array_fill_keys($skus, true);
        $result = [];
        foreach ($snapshot['rows'] ?? [] as $row) {
            if (!is_array($row)) continue;
            if ($selected && !isset($selected[$row['seller_sku'] ?? ''])) continue;
            $result[] = self::values($row);
        }
        return $result;
    }

    /** @param list<array<string,string|int|float|null>> $data */
    public static function xlsx(array $data): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('zip_extension_missing');
        $head = array_keys($data[0] ?? self::values([]));
        $all = [array_combine($head, $head)];
        array_push($all, ...$data);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="17"/><cols>';
        foreach ($head as $i=>$title) {
            $width = max(15,min(32,strlen($title)+4));
            $xml .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        foreach ($all as $idx=>$row) {
            $r=$idx+1;
            $xml .= '<row r="'.$r.'">';
            $i=0;
            foreach ($row as $val) {
                $i++;
                $ref=self::column($i).$r;
                $style=$r===1?'1':(is_numeric($val)&&$val!==null?'2':'0');
                if (is_int($val)||is_float($val)) {
                    $xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.(string)$val.'</v></c>';
                } else {
                    $string=htmlspecialchars((string)($val??''),ENT_XML1|ENT_QUOTES,'UTF-8');
                    $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$string.'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $last=self::column(count($head)).count($all);
        $xml .= '</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';

        $tmp=tempnam(sys_get_temp_dir(),'sv-margin-xlsx-');
        if ($tmp===false) throw new RuntimeException('xlsx_tmp_failed');
        $zip=new ZipArchive();
        try {
            if ($zip->open($tmp,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('xlsx_zip_open_failed');
            $entries=[
                '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    .'<Default Extension="xml" ContentType="application/xml"/>'
                    .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
                '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                    .'</Relationships>',
                'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    .'<sheets><sheet name="Auditoria Amazon" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                    .'</Relationships>',
                'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                    .'<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
                    .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17365D"/><bgColor indexed="64"/></patternFill></fill></fills>'
                    .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                    .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                    .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFill="1" applyFont="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs>'
                    .'</styleSheet>',
                'xl/worksheets/sheet1.xml'=>$xml,
            ];
            foreach($entries as $path=>$body)$zip->addFromString($path,$body);
            $zip->close();
            $bin=file_get_contents($tmp);
            if ($bin===false) throw new RuntimeException('xlsx_read_failed');
            return $bin;
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }

    private static function column(int $index): string
    {
        $r='';
        do {$index--; $r=chr(65+($index%26)).$r;$index=intdiv($index,26);}
        while($index>0);
        return $r;
    }

    /** @param list<array<string,string|int|float|null>> $data */
    public static function pdf(array $data, string $date): string
    {
        $groups = array_chunk($data,17);
        if (!$groups) $groups=[[]];
        $fontId=3;
        $objects=[];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds=[];
        for($i=0;$i<count($groups);$i++)$pageIds[]=(4+2*$i).' 0 R';
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$pageIds).'] /Count '.count($groups).' >>';
        $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        foreach($groups as $index=>$group){
            $content = self::pdfText(27,553,17,'SHOPVIVALIZ  |  AUDITORIA DE MARGENS AMAZON');
            $activeCount=count(array_filter($data,static fn($r):bool => ($r['Ativo Amazon']??'')==='Sim'));
            $content .= self::pdfText(27,532,10,'Data da auditoria: '.$date.'    Anuncios: '.count($data).'    Ativos: '.$activeCount);
            $content .= self::pdfText(27,510,9,'SKU Amazon');
            $content .= self::pdfText(191,510,9,'ERP');
            $content .= self::pdfText(275,510,9,'Canal');
            $content .= self::pdfText(389,510,9,'Preco');
            $content .= self::pdfText(448,510,9,'Custo');
            $content .= self::pdfText(509,510,9,'Marg. atual');
            $content .= self::pdfText(568,510,9,'Unid. conf.');
            $content .= self::pdfText(627,510,9,'Situacao');
            $content .= "0.72 w 27 504 m 813 504 l S\n";
            foreach($group as $i=>$row){
                $y=483-$i*26;
                $get=static fn(string $k):string => (string)($row[$k]??'-');
                $content .= self::pdfText(27,$y,8,mb_strimwidth($get('SKU Amazon'),0,28,'...'));
                $content .= self::pdfText(191,$y,8,mb_strimwidth($get('Produto ERP'),0,15,'...'));
                $content .= self::pdfText(275,$y,8,mb_strimwidth($get('Canal'),0,17,'...'));
                $showPrice=$get('Preco Amazon (R$)');
                if ($showPrice==='-' && $row['Preco cadastro Amazon (R$)']!==null) {
                    $showPrice=$get('Preco cadastro Amazon (R$)').'*';
                }
                $content .= self::pdfText(389,$y,8,$showPrice);
                $showCost=$get('Custo ERP por conjunto vendido (R$)');
                if ($showCost==='-' && ($row['Situacao']??'')!=='kit_cost_unverified'
                    && $row['Custo anterior verificado (R$)']!==null) {
                    $showCost=$get('Custo anterior verificado (R$)').'^';
                }
                $content .= self::pdfText(448,$y,8,$showCost);
                $content .= self::pdfText(509,$y,8,$get('Margem atual estimada (%)'));
                $content .= self::pdfText(568,$y,8,$get('Unidades confirmadas'));
                $state=(($row['Ativo Amazon']??'')==='Sim'?'Ativo: ':'Inativo: ').$get('Situacao');
                if (($row['Divergencia UI Olist']??'Nao')==='Sim')
                    $state='DIVERGENCIA UI OLIST (nao certificado)';
                $content .= self::pdfText(627,$y,8,mb_strimwidth($state,0,35,'...'));
                // A second line makes the comparison unit-vs-period explicit:
                // the first-line price and cost both refer to ONE sold kit.
                // Period profit may exceed the price of one kit, because it
                // includes all financially confirmed sold units.
                $profitPerUnit=$get('Lucro por unidade vendida (R$)');
                $profitPeriod=$get('Lucro acumulado do periodo (R$)');
                $content .= self::pdfText(191,$y-11,7,'Lucro por unidade vendida: '.
                    ($profitPerUnit==='-'?'-':'R$ '.$profitPerUnit));
                $content .= self::pdfText(427,$y-11,7,'Lucro acumulado: '.
                    ($profitPeriod==='-'?'-':'R$ '.$profitPeriod));
                $pieces=$get('Pecas por unidade vendida');
                if ($pieces!=='-') {
                    $content .= self::pdfText(650,$y-11,7,'Pecas por kit: '.$pieces);
                }
                $realized=$get('Receita bruta media por unidade (R$)');
                if ($realized!=='-') {
                    $content .= self::pdfText(191,$y-20,7,
                        'Receita bruta media/unidade: R$ '.$realized);
                }
                if($i%2===1)$content.="0.54 w 27 ".($y-23)." m 813 ".($y-23)." l S\n";
            }
            $content .= self::pdfText(27,24,8,'Preco = anuncio atual; ^ Custo historico nao certificado; custo = kit completo por venda.');
            $content .= self::pdfText(27,12,8,'Margem atual = estimativa no preco vigente; margens historicas e detalhamento disponiveis no Excel.');
            $content .= self::pdfText(755,24,8,'Pagina '.($index+1).'/'.count($groups));
            $pg=4+2*$index;$ct=$pg+1;
            $objects[$pg]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$ct.' 0 R >>';
            $objects[$ct]='<< /Length '.strlen($content)." >>\nstream\n".$content."endstream";
        }
        $pdf="%PDF-1.4\n%ShopVivaliz\n";$offsets=[0];
        ksort($objects);
        foreach($objects as $id=>$dataObject){
            $offsets[$id]=strlen($pdf);
            $pdf.=$id." 0 obj\n".$dataObject."\nendobj\n";
        }
        $start=strlen($pdf);$count=count($objects)+1;
        $pdf.="xref\n0 ".$count."\n0000000000 65535 f \n";
        for($i=1;$i<$count;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
        $pdf.="trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
        return $pdf;
    }

    private static function pdfText(int $x,int $y,int $size,string $message):string
    {
        $latin=iconv('UTF-8','Windows-1252//TRANSLIT', $message);
        if ($latin===false)$latin='';
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$latin);
        return 'BT /F1 '.$size.' Tf '.$x.' '.$y.' Td ('.$escaped.") Tj ET\n";
    }
}

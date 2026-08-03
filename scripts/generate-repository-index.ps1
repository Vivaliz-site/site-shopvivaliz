param(
    [string]$RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $RepositoryRoot

function Get-GitTrackedEntries {
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = 'git'
    $startInfo.Arguments = 'ls-files -s -z'
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.StandardOutputEncoding = [Text.Encoding]::UTF8

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    [void]$process.Start()
    $rawPaths = $process.StandardOutput.ReadToEnd()
    $errorOutput = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw "git ls-files -s failed: $errorOutput" }

    foreach ($record in $rawPaths.Split([char[]]@([char]0), [StringSplitOptions]::RemoveEmptyEntries)) {
        $tabIndex = $record.IndexOf([char]9)
        if ($tabIndex -lt 0) { throw "Unexpected git index record: $record" }
        $header = $record.Substring(0, $tabIndex).Split(' ')
        if ($header.Count -lt 3) { throw "Unexpected git index header: $record" }
        [pscustomobject]@{
            BlobId = $header[1]
            Path = $record.Substring($tabIndex + 1)
        }
    }
}

$trackedEntries = @(Get-GitTrackedEntries)
$tracked = @($trackedEntries | ForEach-Object { $_.Path } | Sort-Object)
$generatedOutputs = @('docs/knowledge/repository-file-index.md', 'docs/audits/repository-hygiene.md')
foreach ($generatedOutput in $generatedOutputs) {
    if ($tracked -notcontains $generatedOutput) { $tracked += $generatedOutput }
}
$generatedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')

$areaByRoot = @{
    'admin' = 'Administracao autenticada'
    'agent-bridge' = 'Ponte de agentes'
    'agents' = 'Agentes e instrucoes operacionais'
    'api' = 'Endpoints HTTP e webhooks'
    'assets' = 'Assets de frontend'
    'auth' = 'Autenticacao e contas'
    'automacoes' = 'Automacoes em portugues'
    'automation' = 'Automacao'
    'automations' = 'Automacao'
    'blog' = 'Blog publico e publicacao'
    'catalogo' = 'Catalogo publico'
    'checkout-v2' = 'Checkout ativo v2'
    'checkout-legacy' = 'Checkout legado'
    'carrinho-legacy' = 'Carrinho legado'
    'config' = 'Configuracao e seguranca'
    'core' = 'Nucleo da aplicacao'
    'css' = 'Estilos'
    'database' = 'Banco de dados'
    'deploy' = 'Deploy e runtime'
    'docs' = 'Documentacao'
    'erp' = 'Integracoes ERP'
    'includes' = 'Bibliotecas PHP compartilhadas'
    'js' = 'Scripts de frontend'
    'migrations' = 'Migracoes de banco'
    'olist' = 'Integracao Olist/Tiny'
    'public' = 'Arquivos publicos'
    'public_html' = 'Document root compativel'
    'scripts' = 'Ferramentas e rotinas de manutencao'
    'tests' = 'Testes'
    'tools' = 'Ferramentas de desenvolvimento'
    'utils' = 'Utilitarios'
    'web' = 'Aplicacao web complementar'
}

function Get-FilePurpose([string]$Path) {
    $leaf = Split-Path -Leaf $Path
    $extension = [IO.Path]::GetExtension($leaf).ToLowerInvariant()
    $root = ($Path -split '/')[0]
    $area = $areaByRoot[$root]
    if (-not $area) { $area = 'Suporte, configuracao ou artefato de projeto' }

    $role = switch -Regex ($extension) {
        '^\.php$' { 'Codigo PHP' ; break }
        '^\.(js|mjs|cjs|ts|tsx|jsx)$' { 'Codigo JavaScript/TypeScript' ; break }
        '^\.(css|scss|sass)$' { 'Estilo visual' ; break }
        '^\.(yml|yaml)$' { 'Configuracao YAML/CI' ; break }
        '^\.json$' { 'Configuracao ou dados JSON' ; break }
        '^\.sql$' { 'Schema, migracao ou consulta SQL' ; break }
        '^\.(md|mdx|txt)$' { 'Documentacao ou instrucao' ; break }
        '^\.(png|jpe?g|gif|webp|svg|ico)$' { 'Imagem ou icone' ; break }
        '^\.(csv|xlsx|ods)$' { 'Planilha ou dados tabulares' ; break }
        '^\.(sh|ps1|py)$' { 'Script operacional' ; break }
        default { 'Artefato de suporte' }
    }

    if ($leaf -match '(?i)(^|[-_.])(test|spec)([-_.]|$)') { $role = 'Teste automatizado' }
    elseif ($Path -match '(?i)(webhook|callback)') { $role = 'Endpoint de callback ou webhook' }
    elseif ($Path -match '(?i)(migration|migrate)') { $role = 'Migracao de banco de dados' }
    elseif ($Path -match '(?i)(readme|changelog|contributing)') { $role = 'Documentacao de projeto' }

    return "$area - $role."
}

function Get-Classification([string]$Path) {
    if ($Path -match '(?i)(^|/)(carrinho-legacy|checkout-legacy)(/|$)|(^|[-_.])legacy([-_.]|$)') { return 'legado declarado - revisar referencia antes de remover' }
    if ($Path -match '(?i)(^|/)(scratch|tmp-gh-email-artifact|\.tmp_shopvivaliz|logs|uploads|dist)(/|$)') { return 'artefato/candidato a higiene - validar uso e politica de retencao' }
    return 'ativo ou ainda nao classificado como legado'
}

function Get-MarkdownCode([string]$Value) {
    return ('`' + $Value.Replace('`', '\`') + '`')
}

function Get-SafePathForDocumentation([string]$Path) {
    $safePath = $Path
    $secretPatterns = @(
        'gh[pousr]_[A-Za-z0-9_]{20,}',
        'github_pat_[A-Za-z0-9_]{40,}',
        'sk-(?:proj-)?[A-Za-z0-9_-]{24,}',
        'xox[baprs]-[A-Za-z0-9-]{20,}',
        'eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}'
    )
    foreach ($pattern in $secretPatterns) {
        $safePath = [regex]::Replace($safePath, $pattern, '<REDACTED_SECRET>', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
    }
    return $safePath
}

$hashGroups = @{}
foreach ($entry in $trackedEntries) {
    $hash = $entry.BlobId
    if (-not $hashGroups.ContainsKey($hash)) { $hashGroups[$hash] = [Collections.Generic.List[string]]::new() }
    $hashGroups[$hash].Add($entry.Path)
}
$duplicateGroups = @($hashGroups.GetEnumerator() | Where-Object { $_.Value.Count -gt 1 } | Sort-Object { $_.Value.Count } -Descending)

$directorySet = [Collections.Generic.HashSet[string]]::new()
foreach ($path in $tracked) {
    $parts = $path -split '/'
    for ($i = 1; $i -lt $parts.Count; $i++) { [void]$directorySet.Add(($parts[0..($i - 1)] -join '/')) }
}

$index = [Text.StringBuilder]::new()
[void]$index.AppendLine('# Indice completo do repositorio')
[void]$index.AppendLine()
[void]$index.AppendLine(('Gerado em {0} a partir de {1} arquivos versionados. O indice nao le ou replica conteudo de segredos; a funcao e inferida por caminho, extensao e convencoes do repositorio.' -f $generatedAt, $tracked.Count))
[void]$index.AppendLine()
[void]$index.AppendLine('## Diretorios')
[void]$index.AppendLine()
[void]$index.AppendLine('| Diretorio | Funcao predominante |')
[void]$index.AppendLine('|---|---|')
foreach ($directory in ($directorySet | Sort-Object)) {
    $root = ($directory -split '/')[0]
    $area = $areaByRoot[$root]
    if (-not $area) { $area = 'Suporte, configuracao ou artefato de projeto' }
    [void]$index.AppendLine(('| {0} | {1} |' -f (Get-MarkdownCode $directory), $area))
}
[void]$index.AppendLine()
[void]$index.AppendLine('## Arquivos')
[void]$index.AppendLine()
[void]$index.AppendLine('| Arquivo | Funcao | Classificacao |')
[void]$index.AppendLine('|---|---|---|')
foreach ($path in $tracked) {
    $safePath = (Get-SafePathForDocumentation $path) -replace '\|', '\\|'
    [void]$index.AppendLine(('| {0} | {1} | {2} |' -f (Get-MarkdownCode $safePath), (Get-FilePurpose $path), (Get-Classification $path)))
}
[IO.File]::WriteAllText((Join-Path $RepositoryRoot 'docs/knowledge/repository-file-index.md'), $index.ToString(), [Text.UTF8Encoding]::new($false))

$hygiene = [Text.StringBuilder]::new()
[void]$hygiene.AppendLine('# Relatorio de higiene do repositorio')
[void]$hygiene.AppendLine()
[void]$hygiene.AppendLine(('Gerado em {0}. Este relatorio aponta candidatos; nao autoriza exclusao automatica.' -f $generatedAt))
[void]$hygiene.AppendLine()
[void]$hygiene.AppendLine('## Resumo')
[void]$hygiene.AppendLine()
[void]$hygiene.AppendLine("- Arquivos versionados: $($tracked.Count)")
[void]$hygiene.AppendLine("- Diretorios versionados: $($directorySet.Count)")
[void]$hygiene.AppendLine("- Grupos de conteudo identico: $($duplicateGroups.Count)")
[void]$hygiene.AppendLine("- Arquivos em caminhos explicitamente legados: $(@($tracked | Where-Object { $_ -match '(?i)legacy' }).Count)")
[void]$hygiene.AppendLine()
[void]$hygiene.AppendLine('## Arquivos identicos')
[void]$hygiene.AppendLine()
if ($duplicateGroups.Count -eq 0) {
    [void]$hygiene.AppendLine('Nenhum grupo de arquivos identicos foi encontrado.')
} else {
    foreach ($group in $duplicateGroups) {
        [void]$hygiene.AppendLine(('### Git blob ID {0}' -f (Get-MarkdownCode $group.Key)))
        foreach ($path in ($group.Value | Sort-Object)) { [void]$hygiene.AppendLine(('- {0}' -f (Get-MarkdownCode (Get-SafePathForDocumentation $path)))) }
        [void]$hygiene.AppendLine()
    }
}

[void]$hygiene.AppendLine('## Candidatos a revisao manual')
[void]$hygiene.AppendLine()
foreach ($path in ($tracked | Where-Object { $_ -match '(?i)(legacy|(^|/)(scratch|tmp-gh-email-artifact|\.tmp_shopvivaliz|logs|uploads|dist)(/|$))' })) {
    [void]$hygiene.AppendLine(('- {0} - {1}' -f (Get-MarkdownCode (Get-SafePathForDocumentation $path)), (Get-Classification $path)))
}
[void]$hygiene.AppendLine()
[void]$hygiene.AppendLine('## Pastas paralelas')
[void]$hygiene.AppendLine()
foreach ($name in @('automacoes', 'automation', 'automations')) {
    $count = @($tracked | Where-Object { $_ -like "$name/*" }).Count
    [void]$hygiene.AppendLine(('- {0}: {1} arquivos. Comparar chamadas e fluxos antes de consolidar.' -f (Get-MarkdownCode "$name/"), $count))
}
[IO.File]::WriteAllText((Join-Path $RepositoryRoot 'docs/audits/repository-hygiene.md'), $hygiene.ToString(), [Text.UTF8Encoding]::new($false))

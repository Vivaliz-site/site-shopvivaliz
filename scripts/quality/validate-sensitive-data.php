<?php
declare(strict_types=1);

$extensions = ['php', 'js', 'json', 'yml', 'yaml', 'md'];
// Require credential-like length/shape so documentation prefixes are not secrets.
// Patterns are assembled to avoid the scanner detecting its own definitions.
$patterns = [
    'OpenAI project key' => '/sk-' . 'proj-[A-Za-z0-9_-]{20,}/',
    'Google API key' => '/AIza' . 'Sy[A-Za-z0-9_-]{25,}/',
    'GitHub token' => '/ghp' . '_[A-Za-z0-9]{30,}/',
    // A real PEM has one or more base64 body lines. Documentation that merely
    // names the BEGIN/END markers must not fail the build.
    'private key' => '/-----BEGIN ' . '(?:RSA |EC |OPENSSH )?PRIVATE KEY-----\R(?:[A-Za-z0-9+\/=]{40,}\R)+-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
];
$documentedExamples = [
    './CONFIGURAR_GITHUB_SECRETS.md', // onboarding example; never loaded at runtime
];
$errors = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $path = str_replace('\\', '/', $file->getPathname());
    if (
        str_starts_with($path, './vendor/')
        || str_starts_with($path, './node_modules/')
        || str_starts_with($path, './.git/')
        || str_starts_with($path, './logs/')
    ) continue;
    if (in_array($path, $documentedExamples, true)) continue;
    if (!in_array(strtolower($file->getExtension()), $extensions, true)) continue;

    $content = (string)@file_get_contents($path);
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $content) === 1) {
            $errors[] = "possible $label in $path";
        }
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "Sensitive data validation passed.\n";

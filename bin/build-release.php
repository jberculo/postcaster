<?php

declare(strict_types=1);

$options = getopt('', ['source-root:']);

$root = dirname(__DIR__);
$sourceRoot = isset($options['source-root']) && is_string($options['source-root']) && $options['source-root'] !== ''
    ? $options['source-root']
    : $root;
$slug = 'postcaster';
$outputDir = $root . DIRECTORY_SEPARATOR . 'dist';
$buildDir = $outputDir . DIRECTORY_SEPARATOR . '_build';
$stagingDir = $buildDir . DIRECTORY_SEPARATOR . $slug;
$pluginFile = $sourceRoot . DIRECTORY_SEPARATOR . $slug . '.php';
$readmeFile = $sourceRoot . DIRECTORY_SEPARATOR . 'readme.txt';
$distIgnoreFile = $sourceRoot . DIRECTORY_SEPARATOR . '.distignore';

if (!is_file($pluginFile)) {
    fwrite(STDERR, "Missing plugin file: {$pluginFile}\n");
    exit(1);
}

if (!is_file($readmeFile)) {
    fwrite(STDERR, "Missing readme file: {$readmeFile}\n");
    exit(1);
}

if (!is_file($distIgnoreFile)) {
    fwrite(STDERR, "Missing .distignore file: {$distIgnoreFile}\n");
    exit(1);
}

$version = detectPluginVersion($pluginFile);
$stableTag = detectStableTag($readmeFile);

if ($version === null) {
    fwrite(STDERR, "Could not read Version from {$pluginFile}\n");
    exit(1);
}

if ($stableTag === null) {
    fwrite(STDERR, "Could not read Stable tag from {$readmeFile}\n");
    exit(1);
}

if ($version !== $stableTag) {
    fwrite(STDERR, "Plugin version {$version} does not match stable tag {$stableTag}\n");
    exit(1);
}

$archiveBasename = "{$slug}-v{$version}.zip";
$archivePath = $outputDir . DIRECTORY_SEPARATOR . $archiveBasename;

deletePath($buildDir);
@unlink($archivePath);

mkdirOrFail($stagingDir);

$excludedPaths = loadExcludedPaths($distIgnoreFile);
copyDistributionFiles($sourceRoot, $stagingDir, $excludedPaths);
createArchive($stagingDir, $archivePath, $buildDir);
deletePath($buildDir);

fwrite(STDOUT, "Created {$archiveBasename}\n");

function detectPluginVersion(string $pluginFile): ?string
{
    $contents = file_get_contents($pluginFile);
    if ($contents === false) {
        return null;
    }

    if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $contents, $matches) !== 1) {
        return null;
    }

    return trim($matches[1]);
}

function detectStableTag(string $readmeFile): ?string
{
    $contents = file_get_contents($readmeFile);
    if ($contents === false) {
        return null;
    }

    if (preg_match('/^Stable tag:\s*(.+)$/mi', $contents, $matches) !== 1) {
        return null;
    }

    return trim($matches[1]);
}

function loadExcludedPaths(string $distIgnoreFile): array
{
    $lines = file($distIgnoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Could not read {$distIgnoreFile}\n");
        exit(1);
    }

    $paths = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $normalized = str_replace('\\', '/', $line);
        $normalized = trim($normalized, '/');

        if ($normalized !== '') {
            $paths[$normalized] = true;
        }
    }

    $paths['.git'] = true;
    $paths['dist'] = true;

    return array_keys($paths);
}

function copyDistributionFiles(string $sourceRoot, string $stagingDir, array $excludedPaths): void
{
    $directoryIterator = new RecursiveDirectoryIterator(
        $sourceRoot,
        FilesystemIterator::SKIP_DOTS
    );

    $iterator = new RecursiveIteratorIterator(
        $directoryIterator,
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($sourcePath, strlen($sourceRoot))), '/');

        if ($relativePath === '') {
            continue;
        }

        if (shouldExclude($relativePath, $excludedPaths)) {
            continue;
        }

        $destinationPath = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if ($item->isDir()) {
            mkdirOrFail($destinationPath);
            continue;
        }

        mkdirOrFail(dirname($destinationPath));

        if (!copy($sourcePath, $destinationPath)) {
            fwrite(STDERR, "Failed to copy {$relativePath}\n");
            exit(1);
        }
    }
}

function shouldExclude(string $relativePath, array $excludedPaths): bool
{
    foreach ($excludedPaths as $excludedPath) {
        if ($relativePath === $excludedPath) {
            return true;
        }

        if (str_starts_with($relativePath, $excludedPath . '/')) {
            return true;
        }
    }

    return false;
}

function createArchive(string $stagingDir, string $archivePath, string $buildDir): void
{
    $zip = new ZipArchive();

    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Could not create archive {$archivePath}\n");
        exit(1);
    }

    $directoryIterator = new RecursiveDirectoryIterator(
        $stagingDir,
        FilesystemIterator::SKIP_DOTS
    );

    $iterator = new RecursiveIteratorIterator(
        $directoryIterator,
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $localName = ltrim(str_replace('\\', '/', substr($path, strlen($buildDir))), '/');

        if ($item->isDir()) {
            $zip->addEmptyDir($localName);
            continue;
        }

        $zip->addFile($path, $localName);
    }

    $zip->close();
}

function mkdirOrFail(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fwrite(STDERR, "Could not create directory {$path}\n");
        exit(1);
    }
}

function deletePath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

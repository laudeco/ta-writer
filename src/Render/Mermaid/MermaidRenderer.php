<?php

declare(strict_types=1);

namespace App\Render\Mermaid;

use Symfony\Component\Process\Process;

/**
 * Renders a mermaid source to PNG with `mmdc -b white -s 2`, the same flags as the AGIR skill, so the colours are the
 * ones of the source. Results are cached by the hash of the source.
 */
final class MermaidRenderer
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $mmdcBin = 'mmdc',
        private readonly ?string $puppeteerConfig = null,
    ) {
    }

    public function available(): bool
    {
        try {
            $process = new Process([$this->mmdcBin, '--version']);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return string absolute path of the PNG
     *
     * @throws MermaidRenderException when mmdc is missing or the source does not parse
     */
    public function render(string $source): string
    {
        $png = sprintf('%s/%s.png', rtrim($this->cacheDir, '/'), hash('sha256', $source));
        if (is_file($png) && filesize($png) > 0) {
            return $png;
        }
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new MermaidRenderException(sprintf('Cannot create %s', $this->cacheDir));
        }

        $input = $png.'.mmd';
        file_put_contents($input, $source);
        $args = [$this->mmdcBin];
        if ($this->puppeteerConfig !== null && is_file($this->puppeteerConfig)) {
            $args[] = '-p';
            $args[] = $this->puppeteerConfig;
        }
        array_push($args, '-b', 'white', '-s', '2', '-i', $input, '-o', $png);

        $process = new Process($args, timeout: 60);
        $process->run();
        @unlink($input);
        if (!$process->isSuccessful() || !is_file($png)) {
            $line = strtok(trim($process->getErrorOutput().' '.$process->getOutput()), "\n") ?: 'unknown error';
            throw new MermaidRenderException(sprintf('mermaid rendering failed: %s', $line));
        }

        return $png;
    }
}

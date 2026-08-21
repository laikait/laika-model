<?php
/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model\Converter;

use Laika\Model\Exceptions\ConverterException;

/**
 * Turns any SQL source into a stream of {@see Statement} objects.
 *
 * Input may be a raw string, a file path, an open resource or an SplFileObject.
 * Everything is read through the same chunked loop, so a one-line CREATE TABLE
 * and a 2 GB mysqldump take the same code path and the same memory.
 */
final class StatementReader
{
    /** Bytes pulled from the source per read. */
    public const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly string $dialect = 'mysql',
        private readonly int $chunkSize = self::CHUNK_SIZE,
    ) {
        if ($this->chunkSize < 1) {
            throw new ConverterException('Chunk size must be at least 1 byte.');
        }
    }

    /**
     * Stream statements from a string, file path, resource or SplFileObject.
     *
     * @param string|resource|\SplFileObject $source
     * @return \Generator<int,Statement>
     */
    public function read(mixed $source): \Generator
    {
        [$handle, $closeIt] = $this->openSource($source);

        $lexer = new Lexer($this->dialect);

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);

                if ($chunk === false) {
                    throw new ConverterException('Failed reading from the SQL source.');
                }

                if ($chunk === '') {
                    continue;
                }

                $lexer->feed($chunk);

                yield from $lexer->drain();
            }

            yield from $lexer->finish();
        } finally {
            if ($closeIt) {
                fclose($handle);
            }
        }
    }

    /**
     * Resolve the input to a readable handle.
     *
     * A string is treated as a file path when a readable file of that name
     * exists, and as literal SQL otherwise — so both `read('dump.sql')` and
     * `read(file_get_contents('dump.sql'))` do what the caller means.
     *
     * @param string|resource|\SplFileObject $source
     * @return array{0:resource,1:bool} Handle, and whether we own it.
     */
    private function openSource(mixed $source): array
    {
        if (is_resource($source)) {
            return [$source, false];
        }

        if ($source instanceof \SplFileObject) {
            $handle = fopen($source->getPathname(), 'rb');

            if ($handle === false) {
                throw new ConverterException("Cannot open [{$source->getPathname()}] for reading.");
            }

            return [$handle, true];
        }

        if (!is_string($source)) {
            throw new ConverterException(
                'SQL source must be a string, a resource or an SplFileObject, got ['
                . get_debug_type($source) . '].'
            );
        }

        if ($this->looksLikePath($source)) {
            $handle = fopen($source, 'rb');

            if ($handle === false) {
                throw new ConverterException("Cannot open [{$source}] for reading.");
            }

            return [$handle, true];
        }

        // Literal SQL — wrap it so there is exactly one read path.
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            throw new ConverterException('Cannot allocate a temporary stream for the SQL source.');
        }

        fwrite($handle, $source);
        rewind($handle);

        return [$handle, true];
    }

    /**
     * A path never contains a newline or a null byte, and must actually exist —
     * so SQL text is never mistaken for a filename.
     */
    private function looksLikePath(string $source): bool
    {
        if ($source === '' || strlen($source) > 4096) {
            return false;
        }

        if (strpbrk($source, "\n\r\0") !== false) {
            return false;
        }

        return @is_file($source);
    }
}

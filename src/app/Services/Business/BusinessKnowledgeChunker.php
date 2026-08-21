<?php

namespace App\Services\Business;

class BusinessKnowledgeChunker
{
    /** @return array<int, string> */
    public function chunk(string $text): array
    {
        $text = trim((string) preg_replace("/\r\n?|\u{2028}|\u{2029}/u", "\n", $text));
        if ($text === '') {
            return [];
        }

        $maximum = (int) config('business-ai.knowledge.chunk_characters', 1800);
        $overlap = min($maximum - 1, (int) config('business-ai.knowledge.chunk_overlap_characters', 180));
        $minimumBoundary = (int) floor($maximum * 0.6);
        $length = mb_strlen($text);
        $offset = 0;
        $chunks = [];

        while ($offset < $length) {
            $remaining = $length - $offset;
            $take = min($maximum, $remaining);
            $candidate = mb_substr($text, $offset, $take);

            if ($remaining > $maximum) {
                $boundary = $this->lastBoundary($candidate, $minimumBoundary);
                if ($boundary !== null) {
                    $candidate = mb_substr($candidate, 0, $boundary);
                    $take = $boundary;
                }
            }

            $chunk = trim($candidate);
            if ($chunk !== '' && ($chunks === [] || $chunks[array_key_last($chunks)] !== $chunk)) {
                $chunks[] = $chunk;
            }

            if ($offset + $take >= $length) {
                break;
            }

            $offset += max(1, $take - $overlap);
            while ($offset < $length && preg_match('/\s/u', mb_substr($text, $offset, 1)) === 1) {
                $offset++;
            }
        }

        return $chunks;
    }

    private function lastBoundary(string $text, int $minimum): ?int
    {
        foreach (["\n", '. ', '; ', ', ', ' '] as $separator) {
            $position = mb_strrpos($text, $separator);
            if ($position !== false && $position >= $minimum) {
                return $position + mb_strlen($separator);
            }
        }

        return null;
    }
}

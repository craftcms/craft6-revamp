<?php

namespace CraftCms\Revamp\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;

class Text
{
    /**
     * Word-wraps console-formatted text to a visible width, keeping each line’s indentation.
     *
     * Formatting tags (like `<options=bold>`) don’t count towards the width. A tag that’s still open at a
     * line break is closed at the end of the line and reopened on the next one, since table cells format each
     * line on its own. Words longer than the width are left whole.
     */
    public static function wrap(string $text, int $width): string
    {
        $formatter = new OutputFormatter;
        $open = [];
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $content = ltrim($paragraph, ' ');
            $indent = str_repeat(' ', strlen($paragraph) - strlen($content));
            $line = $indent.implode('', $open);
            $lineWidth = strlen($indent);
            $empty = true;

            foreach (explode(' ', $content) as $word) {
                $wordWidth = Helper::width(Helper::removeDecoration($formatter, $word));

                if (! $empty && $lineWidth + 1 + $wordWidth > $width) {
                    $lines[] = $line.str_repeat('</>', count($open));
                    $line = $indent.implode('', $open);
                    $lineWidth = strlen($indent);
                    $empty = true;
                }

                $line .= ($empty ? '' : ' ').$word;
                $lineWidth += ($empty ? 0 : 1) + $wordWidth;
                $empty = false;

                // The same tag pattern Symfony’s OutputFormatter uses
                preg_match_all('#<(/?)([a-z][^<>]*+)?>#i', $word, $tags, PREG_SET_ORDER);
                foreach ($tags as $tag) {
                    if ($tag[1] === '/') {
                        array_pop($open);
                    } else {
                        $open[] = $tag[0];
                    }
                }
            }

            $lines[] = $line.str_repeat('</>', count($open));
        }

        return implode("\n", $lines);
    }
}

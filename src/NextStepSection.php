<?php

namespace CraftCms\Prepper\Console;

/**
 * Groups Next steps, so they’re listed in the same order no matter which check, step, or advisor adds them.
 * The cases are listed in display order.
 */
enum NextStepSection
{
    case GeneralConfig;
    case CraftConfig;
    case LegacyElements;
    case Mail;
    case OAuth;
    case Ddev;
    case Filesystems;
    case Packages;
    case Commands;
}

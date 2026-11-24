<?php

namespace Fedale\GridviewBundle\Form\Control;

/**
 * How a `choice` control draws its options (control key `display`).
 */
enum ChoiceDisplay: string
{
    /** A select, the default. */
    case Dropdown = 'dropdown';
    /** Radio buttons, one option per line. */
    case Radio = 'radio';
    /** Radio buttons drawn as buttons side by side (`gv-segmented`). */
    case Segmented = 'segmented';
    /** Checkboxes, one option per line: several options can be chosen. */
    case Checkboxes = 'checkboxes';
}

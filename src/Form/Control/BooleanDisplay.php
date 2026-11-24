<?php

namespace Fedale\GridviewBundle\Form\Control;

/**
 * How a `boolean` control is drawn (control key `display`).
 */
enum BooleanDisplay: string
{
    /** A checkbox, the default. */
    case Checkbox = 'checkbox';
    /** An on/off switch (`gv-switch`, `role="switch"`). */
    case Switch = 'switch';
}

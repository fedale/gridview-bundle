<?php

namespace Fedale\GridviewBundle\Twig;

use Fedale\GridviewBundle\Grid\Gridview;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class OptionsExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('options', [$this, 'renderOptions'], ['is_safe' => ['html']])
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gridview_include', [$this, 'includeToken'], [
                'needs_environment' => true,
                'needs_context'     => true,
                'is_safe'           => ['html'],
            ]),
        ];
    }

    public function includeToken(Environment $env, array $context, Gridview $gridview, string $token): string
    {
        if ($gridview->isSlot($token)) {
            return $env->createTemplate($gridview->slotContent($token))->render($context);
        }

        try {
            return $env->load($gridview->layoutTemplate($token))->render($context);
        } catch (LoaderError $e) {
            return '';
        }
    }

    /**
     */
    public function renderOptions(array $options = []): string
    {
        $str = '';
        if ( count($options) == 0) {
            return $str;
        }
        
        foreach ($options as $key => $value) {
            $str .= $key . '="' . $value . '" ';
        }

        return $str;
    }

}
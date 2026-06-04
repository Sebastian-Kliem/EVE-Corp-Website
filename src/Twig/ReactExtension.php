<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ReactExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('react_component', [$this, 'renderReactComponent'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Renders the HTML anchor for the React component.
     */
    public function renderReactComponent(string $componentName, array $props = []): string
    {
        $propsJson = json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        return sprintf(
            '<div data-react-component="%s" data-react-props="%s"></div>',
            htmlspecialchars($componentName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($propsJson, ENT_QUOTES, 'UTF-8')
        );
    }
}

<?php
/**
 * @package php-svg-lib
 * @link    http://github.com/PhenX/php-svg-lib
 * @author  Fabien Ménager <fabien.menager@gmail.com>
 * @license GNU LGPLv3+ http://www.gnu.org/copyleft/lesser.html
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Svg\Tag;

use WOI\PDF\Vendor\Svg\Style;

class Shape extends AbstractTag
{
    protected function before($attributes)
    {
        $surface = $this->document->getSurface();

        $surface->save();

        $style = $this->makeStyle($attributes);

        $this->setStyle($style);
        $surface->setStyle($style);

        $this->applyTransform($attributes);
    }

    protected function after()
    {
        $surface = $this->document->getSurface();

        if ($this->hasShape) {
            $style = $surface->getStyle();

            $fill   = $style->fill   && is_array($style->fill);
            $stroke = $style->stroke && is_array($style->stroke);

            if ($fill) {
                if ($stroke) {
                    $surface->fillStroke(false);
                } else {
//                    if (is_string($style->fill)) {
//                        /** @var LinearGradient|RadialGradient $gradient */
//                        $gradient = $this->getDocument()->getDef($style->fill);
//
//                        var_dump($gradient->getStops());
//                    }

                    $surface->fill();
                }
            }
            elseif ($stroke) {
                $surface->stroke(false);
            }
            else {
                $surface->endPath();
            }
        }

        $surface->restore();
    }
} 
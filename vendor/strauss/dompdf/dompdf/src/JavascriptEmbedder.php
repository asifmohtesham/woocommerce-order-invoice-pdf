<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 *
 * Modified by wpo on 09-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace WOI\PDF\Vendor\Dompdf;

/**
 * Embeds Javascript into the PDF document
 *
 * @package dompdf
 */
class JavascriptEmbedder
{

    /**
     * @var Dompdf
     */
    protected $_dompdf;

    /**
     * JavascriptEmbedder constructor.
     *
     * @param Dompdf $dompdf
     */
    public function __construct(Dompdf $dompdf)
    {
        $this->_dompdf = $dompdf;
    }

    /**
     * @param $script
     */
    public function insert($script)
    {
        $this->_dompdf->getCanvas()->javascript($script);
    }

    /**
     * @param Frame $frame
     */
    public function render(Frame $frame)
    {
        if (!$this->_dompdf->getOptions()->getIsJavascriptEnabled()) {
            return;
        }

        $this->insert($frame->get_node()->nodeValue);
    }
}

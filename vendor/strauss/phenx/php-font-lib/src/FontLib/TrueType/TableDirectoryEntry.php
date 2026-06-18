<?php
/**
 * @package php-font-lib
 * @link    https://github.com/dompdf/php-font-lib
 * @author  Fabien Ménager <fabien.menager@gmail.com>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\FontLib\TrueType;

use WOI\PDF\Vendor\FontLib\Table\DirectoryEntry;

/**
 * TrueType table directory entry.
 *
 * @package php-font-lib
 */
class TableDirectoryEntry extends DirectoryEntry {
  function __construct(File $font) {
    parent::__construct($font);
  }

  function parse() {
    parent::parse();

    $font           = $this->font;
    $this->checksum = $font->readUInt32();
    $this->offset   = $font->readUInt32();
    $this->length   = $font->readUInt32();
    $this->entryLength += 12;
  }
}


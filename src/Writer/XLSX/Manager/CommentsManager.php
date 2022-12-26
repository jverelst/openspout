<?php

declare(strict_types=1);

namespace OpenSpout\Writer\XLSX\Manager;

use OpenSpout\Common\Helper\Escaper;
use OpenSpout\Common\Entity\Comment;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Entity\Worksheet;
use OpenSpout\Writer\Common\Helper\CellHelper;

/**
 * @internal
 * 
 * This manager takes care of comments: writing them into two files:
 *  - commentsX.xml, containing the actual (rich) text of the comment
 *  - drawings/drawingX.vml, containing the layout of the panel showing the comment
 *
 * Each worksheets gets it's unique set of 2 files, this class will make sure that these 
 * files are created, closed and filled with the required data.
 * 
 */
final class CommentsManager
{
    public const COMMENTS_XML_FILE_HEADER = <<<'EOD'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <comments xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <authors><author>Unknown</author></authors>
            <commentList>
        EOD;

    public const COMMENTS_XML_FILE_FOOTER= <<<'EOD'
            </commentList>
        </comments>
        EOD;

    public const DRAWINGS_VML_FILE_HEADER = <<<'EOD'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <xml xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
          <o:shapelayout v:ext="edit">
            <o:idmap v:ext="edit" data="1"/>
          </o:shapelayout>
          <v:shapetype id="_x0000_t202" coordsize="21600,21600" o:spt="202" path="m,l,21600r21600,l21600,xe">
            <v:stroke joinstyle="miter"/>
            <v:path gradientshapeok="t" o:connecttype="rect"/>
          </v:shapetype>
        EOD;

    public const DRAWINGS_VML_FILE_FOOTER = <<<'EOD'
        </xml>
        EOD;

    private array $commentsFilePointers;

    private array $drawingFilePointers;

    private string $xlFolder;

    private int $shapeId = 1024;

    /** @var Escaper\XLSX Strings escaper */
    private Escaper\XLSX $stringsEscaper;

    /**
     * @param string       $xlFolder       Path to the "xl" folder
     * @param Escaper\XLSX $stringsEscaper Strings escaper
     */
    public function __construct(string $xlFolder, Escaper\XLSX $stringsEscaper)
    {
        $this->commentsFilePointers = [];
        $this->drawingFilePointers = [];
        $this->xlFolder = $xlFolder;
        $this->stringsEscaper = $stringsEscaper;
    }

    /**
     * Create the two comment-files for the given worksheet
     * @param Worksheet $sheet 
     */
    public function createWorksheetCommentFiles(Worksheet $sheet)
    {
        $sheetId = $sheet->getId();
        $commentFp = fopen($this->getCommentsFilePath($sheet), 'w');
        $drawingFp = fopen($this->getDrawingFilePath($sheet), 'w');

        fwrite($commentFp, self::COMMENTS_XML_FILE_HEADER);
        fwrite($drawingFp, self::DRAWINGS_VML_FILE_HEADER);
        
        
        $this->commentsFilePointers[$sheetId] = $commentFp;
        $this->drawingFilePointers[$sheetId] = $drawingFp;
    }

    /**
     * Close the two comment-files for the given worksheet
     * @param Worksheet $sheet 
     */
    public function closeWorksheetCommentFiles(Worksheet $sheet) {
        $sheetId = $sheet->getId();

        $commentFp = $this->commentsFilePointers[$sheetId];
        $drawingFp = $this->drawingFilePointers[$sheetId];
        
        fwrite($commentFp, self::COMMENTS_XML_FILE_FOOTER);
        fwrite($drawingFp, self::DRAWINGS_VML_FILE_FOOTER);
    }

    public function addComments(Worksheet $worksheet, Row $row): void
    {
        $columnIndexZeroBased = 0 + $worksheet->getLastWrittenRowIndex();
        foreach ($row->getCells() as $columnIndexZeroBased => $cell) {
            if ($cell->getComment()) {
                $comment = $cell->getComment();
                $this->addXmlComment($worksheet->getId(), $columnIndexZeroBased, $columnIndexZeroBased, $comment);
                $this->addVmlComment($worksheet->getId(), $columnIndexZeroBased, $columnIndexZeroBased, $comment);
            }
        }
    }

    /**
     * @return string The file path where the comments for the given sheet will be stored
     */
    private function getCommentsFilePath(Worksheet $sheet): string
    {
        return $this->xlFolder.\DIRECTORY_SEPARATOR.'comments'.($sheet->getId()).'.xml';
    }

    /**
     * @return string The file path where the VML comments for the given sheet will be stored
     */
    private function getDrawingFilePath(Worksheet $sheet): string
    {
        return $this->xlFolder.\DIRECTORY_SEPARATOR.'drawings'.\DIRECTORY_SEPARATOR.'vmlDrawing'.($sheet->getId()).'.vml';
    }

    /**
     * Add a comment to the commentsX.xml file.
     * @param int $sheetId                  The id of the sheet (starting with 1)
     * @param int $rowIndexZeroBased        The row index, starting at 0, of the cell with the comment
     * @param int $columnIndexZeroBased     The column index, starting at 0, of the cell with the comment
     * @param Comment $comment              The actual comment
     */
    private function addXmlComment(int $sheetId, int $rowIndexZeroBased, int $columnIndexZeroBased, Comment $comment): void
    {
        $commentsFilePointer = $this->commentsFilePointers[$sheetId];
        $rowIndexOneBased = $rowIndexZeroBased + 1;
        $columnLetters = CellHelper::getColumnLettersFromColumnIndex($columnIndexZeroBased);

        $commentxml = '<comment ref="'.$columnLetters.$rowIndexOneBased.'" authorId="0"><text>';
        foreach (explode('\n', $comment->getMessage()) as $line) {
            $commentxml .= '<r>';
            $commentxml .= '<rPr><sz val="10"/><color rgb="FF000000"/><rFont val="Tahoma"/><family val="2"/></rPr>';
            $commentxml .= '<t>' . $this->stringsEscaper->escape($line) . '</t>';
            $commentxml .= '</r>';
        }
        $commentxml .= '</text></comment>';

        fwrite($commentsFilePointer, $commentxml);
    }

    /**
     * Add a comment to the vmlDrawingX.vml file.
     * @param int $sheetId                  The id of the sheet (starting with 1)
     * @param int $rowIndexZeroBased        The row index, starting at 0, of the cell with the comment
     * @param int $columnIndexZeroBased     The column index, starting at 0, of the cell with the comment
     * @param Comment $comment              The actual comment
     */
    private function addVmlComment(int $sheetId, int $rowIndexZeroBased, int $columnIndexZeroBased, Comment $comment): void
    {
        $drawingFilePointer = $this->drawingFilePointers[$sheetId];
        $this->shapeId++;

        $drawingVml = '<v:shape id="_x0000_s' . $this->shapeId . '"';
        $drawingVml .= ' type="#_x0000_t202" style="position:absolute;margin-left:59.25pt;margin-top:1.5pt;width:400pt;height:100pt;z-index:1;visibility:hidden" fillcolor="#FFFFE1" o:insetmode="auto">';
        $drawingVml .= '<v:fill color2="#FFFFE1"/>';
        $drawingVml .= '<v:shadow on="t" color="black" obscured="t"/>';
        $drawingVml .= '<v:path o:connecttype="none"/>';
        $drawingVml .= '<v:textbox style="mso-direction-alt:auto">';
        $drawingVml .= '  <div style="text-align:left"/>';
        $drawingVml .= '</v:textbox>';
        $drawingVml .= '<x:ClientData ObjectType="Note">';
        $drawingVml .= '  <x:MoveWithCells/>';
        $drawingVml .= '          <x:SizeWithCells/>';
        $drawingVml .= '  <x:AutoFill>False</x:AutoFill>';
        $drawingVml .= '  <x:Row>' . $rowIndexZeroBased . '</x:Row>';
        $drawingVml .= '  <x:Column>' . $columnIndexZeroBased . '</x:Column>';
        $drawingVml .= '</x:ClientData>';
        $drawingVml .= '</v:shape>';

        fwrite($drawingFilePointer, $drawingVml);
    }
}

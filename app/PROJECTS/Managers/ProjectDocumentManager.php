<?php



declare(strict_types=1);



namespace App\PROJECTS\Managers;



use App\DOCUMENTS\Repos\PdoDocumentTemplateRepository;



use App\DOCUMENTS\Services\DocumentMergeService;



use App\PROJECTS\Repos\PdoProjectDocumentRepository;

use App\PROJECTS\Repos\PdoProjectPaletteRepository;



use App\PROJECTS\Repos\PdoProjectRepository;



use App\PROJECTS\Repos\PdoProjectScopeRepository;



use PDO;



use RuntimeException;



final class ProjectDocumentManager



{



    private PdoProjectRepository $projects;



    private PdoProjectScopeRepository $scopes;



    private PdoProjectDocumentRepository $documents;

    private PdoProjectPaletteRepository $palettes;



    private PdoDocumentTemplateRepository $templates;



    private DocumentMergeService $merge;



    public function __construct(



        private PDO $pdo



    ) {



        $this->projects =



            new PdoProjectRepository(



                $this->pdo



            );



        $this->scopes =



            new PdoProjectScopeRepository(



                $this->pdo



            );



        $this->documents =



            new PdoProjectDocumentRepository(



                $this->pdo



            );



        $this->palettes =

            new PdoProjectPaletteRepository(

                $this->pdo

            );



$this->templates =



            new PdoDocumentTemplateRepository(



                $this->pdo



            );



        $this->merge =



            new DocumentMergeService();



    }



    /**



     * @return array<int, array<string, mixed>>



     */



    public function listProjectDocuments(



        int $projectId



    ): array {



        $this->assertProjectExists(



            $projectId



        );



        return



            $this->documents



                ->listByProjectId(



                    $projectId



                );



    }



    /**



     * @return array<string, mixed>



     */



    public function generate(



        array $payload



    ): array {



        $projectId =



            isset(



                $payload['project_id']



            )



                ? (int)$payload['project_id']



                : 0;



        $project =



            $this->assertProjectExists(



                $projectId



            );



        $scopeId =



            isset(



                $payload['scope_id']



            )



                ? (int)$payload['scope_id']



                : 0;



        if ($scopeId > 0) {



            $scope =



                $this->scopes



                    ->findById(



                        $scopeId



                    );



            if ($scope === null) {



                throw new RuntimeException(



                    "Scope {$scopeId} was not found."



                );



            }



            if (



                (int)$scope['project_id']



                !==



                $projectId



            ) {



                throw new RuntimeException(



                    'Scope does not belong to this Project.'



                );



            }



        } else {



            $scope =



                $this->scopes



                    ->findMainByProjectId(



                        $projectId



                    );



        }



        if ($scope === null) {



            throw new RuntimeException(



                'Save the Project Scope before generating a document.'



            );



        }



        $templateKey =



            trim(



                (string)(



                    $payload['template_key']



                    ?? 'scope_agreement'



                )



            );



        if ($templateKey === '') {



            throw new RuntimeException(



                'template_key required.'



            );



        }



        $template =



            $this->templates



                ->findActiveByKey(



                    $templateKey



                );



        if ($template === null) {



            throw new RuntimeException(



                "Active document template '{$templateKey}' was not found."



            );



        }



        $documentType =



            trim(



                (string)(



                    $template['template_type']



                    ?? 'document'



                )



            )



            ?: 'document';

        $approvalRequired =
            (int)(
                $template['approval_required']
                ?? 0
            ) === 1;



        $projectPalettes =

            $this->palettes

                ->listForProject(

                    $projectId

                );



        $plainValues =

            $this->plainMergeValues(

                $project,

                $scope,

                $projectPalettes

            );



        $htmlValues =

            $this->htmlMergeValues(

                $project,

                $scope,

                $projectPalettes

            );



        $title =



            trim(



                $this->merge



                    ->render(



                        (string)(



                            $template['title_template']



                            ?? ''



                        ),



                        $plainValues



                    )



            );



        if ($title === '') {



            $title =



                (string)(



                    $template['label']



                    ?? 'Document'



                );



        }



        $contentHtml =



            trim(



                $this->merge



                    ->render(



                        (string)(



                            $template['html_template']



                            ?? ''



                        ),



                        $htmlValues



                    )



            );



        if ($contentHtml === '') {



            $text =



                $this->merge



                    ->render(



                        (string)(



                            $template['text_template']



                            ?? ''



                        ),



                        $plainValues



                    );



            $contentHtml =



                '<pre>'



                . htmlspecialchars(



                    $text,



                    ENT_QUOTES | ENT_SUBSTITUTE,



                    'UTF-8'



                )



                . '</pre>';



        }



        $scopeId =



            (int)$scope['id'];



        $draft =



            $this->documents



                ->findReusableDraft(



                    $projectId,



                    $scopeId,



                    $templateKey



                );



        if ($draft !== null) {



            $documentId =



                (int)$draft['id'];



            $this->documents



                ->updateDraft(



                    $documentId,



                    $documentType,



                    $title,



                    $contentHtml,

                    $approvalRequired



                );



        } else {



            $documentId =



                $this->documents



                    ->createDraft(



                        $projectId,



                        $scopeId,



                        $documentType,



                        $templateKey,



                        $title,



                        $contentHtml,

                        $approvalRequired



                    );



        }



        $document =



            $this->documents



                ->findById(



                    $documentId



                );



        if ($document === null) {



            throw new RuntimeException(



                'Generated document could not be reloaded.'



            );



        }



        return $document;



    }



    /**



     * @return array<string, mixed>



     */



    private function assertProjectExists(



        int $projectId



    ): array {



        if ($projectId <= 0) {



            throw new RuntimeException(



                'Valid project_id required.'



            );



        }



        $project =



            $this->projects



                ->findById(



                    $projectId



                );



        if ($project === null) {



            throw new RuntimeException(



                "Project {$projectId} was not found."



            );



        }



        return $project;



    }



    /**



     * @param array<string, mixed> $project



     * @param array<string, mixed> $scope



     * @return array<string, string>



     */



    private function plainMergeValues(

        array $project,

        array $scope,

        array $projectPalettes

    ): array {



        return [



            'client_name' =>



                $this->plain(



                    $project['client_name']



                    ?? ''



                ),



            'project_name' =>



                $this->plain(



                    $project['project_name']



                    ?? ('Project #' . $project['id'])



                ),



            'property_address' =>



                $this->plain(



                    $project['property_address']



                    ?? ''



                ),



            // Compatibility alias for templates that used project_address.



            'project_address' =>



                $this->plain(



                    $project['property_address']



                    ?? ''



                ),



            'project_goal' =>



                $this->plain(



                    $scope['project_goal']



                    ?? ''



                ),



            'areas_covered' =>



                $this->plain(



                    $scope['areas_covered']



                    ?? ''



                ),



            'scope_fee' =>



                $this->formatMoney(



                    $scope['scope_fee']



                    ?? null



                ),



            'final_color_schedule' =>

                $this->finalColorSchedulePlain(

                    $projectPalettes

                ),



        ];



    }



    /**



     * @param array<string, mixed> $project



     * @param array<string, mixed> $scope



     * @return array<string, string>



     */



    private function htmlMergeValues(

        array $project,

        array $scope,

        array $projectPalettes

    ): array {



        return [



            'client_name' =>



                $this->html(



                    $project['client_name']



                    ?? ''



                ),



            'project_name' =>



                $this->html(



                    $project['project_name']



                    ?? ('Project #' . $project['id'])



                ),



            'property_address' =>



                $this->html(



                    $project['property_address']



                    ?? ''



                ),



            // Compatibility alias for templates that used project_address.



            'project_address' =>



                $this->html(



                    $project['property_address']



                    ?? ''



                ),



            'project_goal' =>



                nl2br(



                    $this->html(



                        $scope['project_goal']



                        ?? ''



                    ),



                    false



                ),



            'areas_covered' =>



                $this->areasHtml(



                    $scope['areas_covered']



                    ?? ''



                ),



            'scope_fee' =>



                $this->html(



                    $this->formatMoney(



                        $scope['scope_fee']



                        ?? null



                    )



                ),



            'final_color_schedule' =>

                $this->finalColorScheduleHtml(

                    $projectPalettes

                ),



        ];



    }





    /**

     * @param array<int, array<string, mixed>> $projectPalettes

     */

    private function finalColorSchedulePlain(

        array $projectPalettes

    ): string {

        $sections = [];



        foreach ($projectPalettes as $palette) {

            if (

                (int)($palette['is_final'] ?? 0)

                !== 1

            ) {

                continue;

            }



            $lines = [

                $this->projectPaletteLabel(

                    $palette

                ),

                'Color | Brand | Placement',

            ];



            foreach (

                (

                    is_array(

                        $palette['colors']

                        ?? null

                    )

                        ? $palette['colors']

                        : []

                )

                as $color

            ) {

                $lines[] =

                    $this->plain(

                        $color['color_name']

                        ?? ''

                    )

                    . ' | '

                    . $this->plain(

                        $color['color_brand_name']

                        ?? $color['color_brand']

                        ?? ''

                    )

                    . ' | '

                    . $this->plain(

                        $color['role']

                        ?? ''

                    );

            }



            $sections[] =

                implode(

                    PHP_EOL,

                    $lines

                );

        }



        return implode(

            PHP_EOL . PHP_EOL,

            $sections

        );

    }





    /**

     * @param array<int, array<string, mixed>> $projectPalettes

     */

    private function finalColorScheduleHtml(

        array $projectPalettes

    ): string {

        $sections = [];



        foreach ($projectPalettes as $palette) {

            if (

                (int)($palette['is_final'] ?? 0)

                !== 1

            ) {

                continue;

            }



            $rows = [];



            foreach (

                (

                    is_array(

                        $palette['colors']

                        ?? null

                    )

                        ? $palette['colors']

                        : []

                )

                as $color

            ) {

                $hex = ltrim(trim((string)($color['color_hex6'] ?? '')), '#');
                $swatch = preg_match('/^[0-9a-fA-F]{6}$/D', $hex) === 1
                    ? '<span class="document-color-schedule__swatch" aria-hidden="true"'
                        . ' style="display:inline-block;width:28px;height:28px;box-sizing:border-box;'
                        . 'vertical-align:middle;margin-right:8px;border:1px solid #999;border-radius:3px;'
                        . 'background-color:#' . $hex . ';print-color-adjust:exact;-webkit-print-color-adjust:exact;"></span>'
                    : '';
                $brand = $this->html(
                    $color['color_brand_name'] ?? $color['color_brand'] ?? ''
                );

                $rows[] =

                    '<tr>'

                    . '<td>'

                    . $swatch

                    . '<span class="document-color-schedule__name">'

                    . $this->html(

                        $color['color_name']

                        ?? ''

                    )

                    . ($brand !== ''
                        ? '<span class="document-color-schedule__mobile-brand" style="display:none">, ' . $brand . '</span>'
                        : '')

                    . '</span>'

                    . '</td>'

                    . '<td>'

                    . $this->html(

                        $color['color_brand_name']

                        ?? $color['color_brand']

                        ?? ''

                    )

                    . '</td>'

                    . '<td>'

                    . $this->html(

                        $color['role']

                        ?? ''

                    )

                    . '</td>'

                    . '</tr>';

            }



            $sections[] =

                '<section class="document-color-schedule__palette">'

                . '<h3>'

                . $this->html(

                    $this->projectPaletteLabel(

                        $palette

                    )

                )

                . '</h3>'

                . '<table class="document-color-schedule__table document-color-schedule__table--swatches">'

                . '<thead>'

                . '<tr>'

                . '<th>Color</th>'

                . '<th>Brand</th>'

                . '<th>Placement</th>'

                . '</tr>'

                . '</thead>'

                . '<tbody>'

                . implode('', $rows)

                . '</tbody>'

                . '</table>'

                . '</section>';

        }



        if ($sections === []) {

            return '';

        }

        // Keep responsive layout with the saved document, including shared REX views.
        $mobileStyles = <<<'HTML'
<style>
@media screen and (max-width: 560px) {
  .document-color-schedule__table--swatches { font-size: 14px; line-height: 1.4; }
  .document-color-schedule__table--swatches thead { display: none; }
  .document-color-schedule__table--swatches tbody { display: block; }
  .document-color-schedule__table--swatches tr {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    grid-template-rows: auto auto;
    column-gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid #e5e9ec;
  }
  .document-color-schedule__table--swatches tr:last-child { border-bottom: 0; }
  .document-color-schedule__table--swatches td:first-child { display: contents; }
  .document-color-schedule__table--swatches .document-color-schedule__swatch {
    grid-column: 1;
    grid-row: 1 / 3;
    width: 40px !important;
    height: 40px !important;
    margin: 0 !important;
  }
  .document-color-schedule__table--swatches .document-color-schedule__name {
    grid-column: 2;
    grid-row: 1;
    overflow-wrap: break-word;
  }
  .document-color-schedule__table--swatches .document-color-schedule__mobile-brand { display: inline !important; }
  .document-color-schedule__table--swatches td:nth-child(2) { display: none; }
  .document-color-schedule__table--swatches td:nth-child(3) {
    grid-column: 2;
    grid-row: 2;
    width: auto;
    padding: 0;
    border: 0;
    overflow-wrap: break-word;
  }
}
</style>
HTML;


        return

            '<div class="document-color-schedule">'

            . $mobileStyles

            . implode('', $sections)

            . '</div>';

    }





    /**

     * @param array<string, mixed> $palette

     */

    private function projectPaletteLabel(

        array $palette

    ): string {

        $label =

            trim(

                (string)(

                    $palette['display_title']

                    ?? ''

                )

            );



        if ($label !== '') {

            return $label;

        }



        $label =

            trim(

                (string)(

                    $palette['nickname']

                    ?? ''

                )

            );



        if ($label !== '') {

            return $label;

        }



        return

            'Palette #'

            . (int)(

                $palette['saved_palette_id']

                ?? 0

            );

    }





    private function areasHtml(



        mixed $value



    ): string {



        $lines =



            preg_split(



                '/\R+/',



                trim(



                    (string)$value



                )



            )



            ?: [];



        $items = [];



        foreach ($lines as $line) {



            $line = trim($line);



            if ($line === '') {



                continue;



            }



            $items[] =



                '<li>'



                . $this->html($line)



                . '</li>';



        }



        if ($items === []) {



            return '';



        }



        return



            '<ul>'



            . implode('', $items)



            . '</ul>';



    }



    private function formatMoney(



        mixed $value



    ): string {



        if (



            $value === null



            ||



            $value === ''



            ||



            !is_numeric($value)



        ) {



            return '';



        }



        return '$'



            . number_format(



                (float)$value,



                2,



                '.',



                ','



            );



    }



    private function plain(



        mixed $value



    ): string {



        return trim(



            (string)$value



        );



    }



    private function html(



        mixed $value



    ): string {



        return htmlspecialchars(



            trim(



                (string)$value



            ),



            ENT_QUOTES | ENT_SUBSTITUTE,



            'UTF-8'



        );



    }



}

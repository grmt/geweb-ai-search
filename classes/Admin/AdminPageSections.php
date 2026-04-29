<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminPageSections {
    private const STATUS_COLOR_ERROR = '#d63638';
    private const DEFAULT_CONVERSATION_LIMIT = 50;

    private ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager) {
        $this->conversationManager = $conversationManager;
    }

    public function renderReferencedDocumentsTable(?array $items = null): void {
        $table = new ReferencedDocumentListTable();
        $table->prepare_items($items);
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-referenced-documents-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="documents">
            <?php $table->search_box(__('Search', 'geweb-ai-search'), 'geweb-referenced-documents'); ?>
            <?php $table->display(); ?>
        </form>
        <?php
    }

    public function renderGeminiStoresTable(): void {
        $table = new GeminiStoreListTable();
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-gemini-stores-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="stores">
            <?php $table->display(); ?>
        </form>
        <?php
    }

    public function renderConversationsTable(): void {
        $conversations = $this->conversationManager->getConversationLog();
        $frontendAiPageUrl = FrontendAiContext::getFrontendAiPageUrl();
        $latestConversation = isset($conversations[0]) && is_array($conversations[0]) ? $conversations[0] : [];
        $latestConversationId = isset($latestConversation['id']) ? (string) $latestConversation['id'] : '';

        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(_n('%d saved chat.', '%d saved chats.', count($conversations), 'geweb-ai-search'), count($conversations)));
        echo '</p>';
        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(__('The %d most recently used chats are kept automatically; the oldest unused ones are pruned first.', 'geweb-ai-search'), self::DEFAULT_CONVERSATION_LIMIT));
        echo '</p>';

        if (empty($conversations)) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;"><p>';
            echo esc_html__('No saved chats yet. A chat is added here after a successful AI response.', 'geweb-ai-search');
            echo '</p></div>';
            return;
        }

        if ($frontendAiPageUrl !== '' && $latestConversationId !== '') {
            $latestConversationUrl = FrontendAiContext::getFrontendAiConversationUrl($latestConversationId);
            echo '<p style="margin:0 0 12px;">';
            echo '<a class="button button-primary" href="' . esc_url($latestConversationUrl) . '">Open Latest Chat</a>';
            echo '</p>';
        }

        $table = new ConversationListTable($conversations, $frontendAiPageUrl);
        $table->prepare_items();
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="geweb-conversations-table-form">';
        echo '<input type="hidden" name="page" value="geweb-ai-search">';
        echo '<input type="hidden" name="geweb_tab" value="conversations">';
        $table->search_box(__('Search chats', 'geweb-ai-search'), 'geweb-conversations');
        $table->display();
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $args
     * @return void
     */
    public function renderModelDiagnosticsSection(array $args): void {
        (new AdminModelDiagnosticsSectionRenderer())->render($args);
    }

    /**
     * @param string $storeName
     * @param string $storeLabel
     * @param array<int,array<string,mixed>> $documents
     * @return void
     */
    public function renderGeminiStoreDocumentsPanel(string $storeName, string $storeLabel, array $documents): void {
        ?>
        <div id="geweb-gemini-store-documents-panel" data-store-name="<?php echo esc_attr($storeName); ?>" style="margin-top:20px; padding:16px; background:#fff; border:1px solid #dcdcde;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
                <div>
                    <strong id="geweb-gemini-store-documents-title"><?php echo esc_html($storeLabel !== '' ? $storeLabel : $storeName); ?></strong>
                    <div id="geweb-gemini-store-documents-subtitle" class="description" style="margin-top:4px;">
                        Uploaded items in the selected Gemini File Search Store.
                    </div>
                </div>
                <button type="button" class="button" id="geweb-refresh-gemini-store-documents" <?php disabled($storeName === ''); ?>>Refresh List</button>
            </div>
            <div id="geweb-gemini-store-documents-status" class="description" style="margin-bottom:12px; color:#646970;">
                <?php echo $storeName === '' ? 'Select a store to view uploaded items.' : 'Showing uploaded items for the selected store.'; ?>
            </div>
            <p id="geweb-gemini-store-documents-error" class="description" style="margin:0 0 12px; color:<?php echo esc_attr(self::STATUS_COLOR_ERROR); ?>; display:none;"></p>
            <div id="geweb-gemini-store-documents-container">
                <?php
                if ($storeName === '') {
                    echo '<p style="margin:0;">Select a store to view uploaded items.</p>';
                } else {
                    echo GeminiStoreListTable::renderDocumentList($documents); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{name:string,label:string,documents:array<int,array<string,mixed>>}
     */
    public function getInitialGeminiStoreSelection(array $items): array {
        $selection = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['is_active'])) {
                continue;
            }

            $selection = $item;
            break;
        }

        if (empty($selection)) {
            $selection = isset($items[0]) && is_array($items[0]) ? $items[0] : [];
        }

        $name = (string) ($selection['name'] ?? '');
        $label = (string) ($selection['display_name'] ?? '');
        if ($label === '') {
            $label = $name;
        }

        return [
            'name' => $name,
            'label' => $label,
            'documents' => isset($selection['documents']) && is_array($selection['documents']) ? $selection['documents'] : [],
        ];
    }

    public function supportsFileSearchModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        $supportsFileSearch = false;
        if ($normalizedModel !== '') {
            $supportsFileSearch = in_array($normalizedModel, ['gemini-flash-latest', 'gemini-pro-latest'], true);
            if (!$supportsFileSearch && !$this->isExcludedFileSearchModel($normalizedModel)) {
                $supportsFileSearch = preg_match('/^gemini-\d[a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $normalizedModel) === 1;
            }
        }

        return $supportsFileSearch;
    }

    private function isExcludedFileSearchModel(string $normalizedModel): bool {
        $isExcluded = false;
        foreach ([
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
            'image',
            'video',
            'live',
            'robotics',
            'deep-research',
            'computer-use',
        ] as $fragment) {
            if (strpos($normalizedModel, $fragment) !== false) {
                $isExcluded = true;
                break;
            }
        }

        return $isExcluded;
    }
}

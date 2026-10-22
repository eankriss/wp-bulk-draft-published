<?php
/**
 * Plugin Name: Bulk Draft Published Posts
 * Description: Safely move published Posts, Pages, and supported custom post types to Draft in bulk, manually or from an uploaded CSV/XLSX list.
 * Version: 1.4.0
 * Author: Custom Utility
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-bulk-draft-published
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WBDP_Bulk_Draft_Published {
    const VERSION = '1.4.0';
    const PAGE_SLUG = 'wbdp-bulk-draft';
    const NONCE_ACTION = 'wbdp_bulk_draft_action';
    const NONCE_NAME = 'wbdp_nonce';
    const IMPORT_NONCE_ACTION = 'wbdp_import_preview_action';
    const LOG_OPTION = 'wbdp_activity_logs';
    const MAX_LOGS = 200;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_wbdp_bulk_draft', array( __CLASS__, 'handle_tools_bulk_draft' ) );
        add_action( 'admin_post_wbdp_import_bulk_draft', array( __CLASS__, 'handle_import_bulk_draft' ) );
        add_action( 'admin_post_wbdp_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
        add_action( 'wp_ajax_wbdp_import_preview', array( __CLASS__, 'ajax_import_preview' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_native_bulk_actions' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
    }

    public static function get_supported_post_types() {
        $objects = get_post_types(
            array(
                'show_ui' => true,
            ),
            'objects'
        );

        $excluded = array(
            'attachment', 'revision', 'nav_menu_item', 'custom_css',
            'customize_changeset', 'oembed_cache', 'user_request',
            'wp_block', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        );

        $supported = array();

        foreach ( $objects as $name => $object ) {
            if ( in_array( $name, $excluded, true ) ) {
                continue;
            }

            if ( empty( $object->cap->edit_posts ) || ! current_user_can( $object->cap->edit_posts ) ) {
                continue;
            }

            $supported[ $name ] = $object;
        }

        return apply_filters( 'wbdp_supported_post_types', $supported );
    }

    public static function admin_menu() {
        add_management_page(
            __( 'Bulk Draft Published Posts', 'wp-bulk-draft-published' ),
            __( 'Bulk Draft Published', 'wp-bulk-draft-published' ),
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_tools_page' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wbdp-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'wbdp-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.js',
            array(),
            self::VERSION,
            true
        );

        wp_localize_script(
            'wbdp-admin',
            'WBDP_IMPORT',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::IMPORT_NONCE_ACTION ),
            )
        );
    }

    public static function register_native_bulk_actions() {
        foreach ( self::get_supported_post_types() as $post_type => $object ) {
            add_filter( 'bulk_actions-edit-' . $post_type, array( __CLASS__, 'add_native_bulk_action' ) );
            add_filter( 'handle_bulk_actions-edit-' . $post_type, array( __CLASS__, 'handle_native_bulk_action' ), 10, 3 );
        }
    }

    public static function add_native_bulk_action( $actions ) {
        $actions['wbdp_move_to_draft'] = __( 'Move published items to Draft', 'wp-bulk-draft-published' );
        return $actions;
    }

    public static function handle_native_bulk_action( $redirect_to, $doaction, $post_ids ) {
        if ( 'wbdp_move_to_draft' !== $doaction ) {
            return $redirect_to;
        }

        $result = self::draft_posts( $post_ids, 'Native Bulk Action' );

        return add_query_arg(
            array(
                'wbdp_drafted' => $result['drafted'],
                'wbdp_skipped' => $result['skipped'],
                'wbdp_failed'  => $result['failed'],
            ),
            $redirect_to
        );
    }

    public static function admin_notices() {
        if ( ! isset( $_GET['wbdp_drafted'] ) ) {
            return;
        }

        $drafted = absint( $_GET['wbdp_drafted'] );
        $skipped = isset( $_GET['wbdp_skipped'] ) ? absint( $_GET['wbdp_skipped'] ) : 0;
        $failed  = isset( $_GET['wbdp_failed'] ) ? absint( $_GET['wbdp_failed'] ) : 0;

        $message = sprintf(
            __( '%1$d item(s) moved to Draft. %2$d skipped. %3$d failed.', 'wp-bulk-draft-published' ),
            $drafted,
            $skipped,
            $failed
        );

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            $failed > 0 ? 'warning' : 'success',
            esc_html( $message )
        );
    }

    public static function render_tools_page() {
        $post_types = self::get_supported_post_types();

        if ( empty( $post_types ) ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Draft Published Posts', 'wp-bulk-draft-published' ) . '</h1>';
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No editable post types are available for your account.', 'wp-bulk-draft-published' ) . '</p></div></div>';
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'file';
        if ( ! in_array( $active_tab, array( 'file', 'manual' ), true ) ) {
            $active_tab = 'file';
        }

        $requested_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : 'post';
        if ( ! isset( $post_types[ $requested_type ] ) ) {
            $requested_type = array_key_first( $post_types );
        }

        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;

        $allowed_per_page = array( 25, 50, 100, 200 );
        if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
            $per_page = 50;
        }

        $query = new WP_Query(
            array(
                'post_type'              => $requested_type,
                'post_status'            => 'publish',
                's'                      => $search,
                'posts_per_page'         => $per_page,
                'paged'                  => $paged,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $post_type_object = $post_types[ $requested_type ];
        $logs = self::get_logs();
        $today_count = 0;
        $success_count = 0;
        foreach ( $logs as $log ) {
            if ( ! empty( $log['timestamp'] ) && wp_date( 'Y-m-d', strtotime( $log['timestamp'] ) ) === wp_date( 'Y-m-d' ) ) {
                $today_count++;
            }
            if ( isset( $log['result'] ) && 'drafted' === $log['result'] ) {
                $success_count++;
            }
        }
        ?>
        <div class="wrap wbdp-wrap">
            <h1><?php esc_html_e( 'Bulk Draft Published Posts', 'wp-bulk-draft-published' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Move published content to Draft manually or by uploading a CSV/XLSX list. The plugin changes only post status.', 'wp-bulk-draft-published' ); ?>
            </p>

            <nav class="nav-tab-wrapper wbdp-tabs" data-active-tab="<?php echo esc_attr( $active_tab ); ?>" aria-label="Bulk Draft views">
                <button type="button" class="nav-tab" data-wbdp-tab="file">Bulk Update From File</button>
                <button type="button" class="nav-tab" data-wbdp-tab="manual">Manual Selection</button>
            </nav>

            <div class="wbdp-dashboard-layout">
                <main class="wbdp-main-column">
                    <section class="wbdp-tab-panel" data-wbdp-panel="file">
            <div class="wbdp-card wbdp-import-card">
                <div class="wbdp-section-heading">
                    <div>
                        <h2><?php esc_html_e( 'Bulk Update From File', 'wp-bulk-draft-published' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Upload a CSV or XLSX report and automatically match the rows to WordPress content instead of finding each item manually.', 'wp-bulk-draft-published' ); ?>
                        </p>
                    </div>
                    <span class="wbdp-badge">NEW</span>
                </div>

                <div class="wbdp-import-grid">
                    <label>
                        <strong><?php esc_html_e( 'Target post type', 'wp-bulk-draft-published' ); ?></strong>
                        <select id="wbdp-import-post-type">
                            <?php foreach ( $post_types as $name => $object ) : ?>
                                <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $requested_type, $name ); ?>>
                                    <?php echo esc_html( $object->labels->name . ' (' . $name . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <strong><?php esc_html_e( 'Upload list', 'wp-bulk-draft-published' ); ?></strong>
                        <input id="wbdp-import-file" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    </label>

                    <label>
                        <strong><?php esc_html_e( 'Match rows by', 'wp-bulk-draft-published' ); ?></strong>
                        <select id="wbdp-import-match">
                            <option value="auto"><?php esc_html_e( 'Auto: ID → Slug → Preview URL → Exact Title', 'wp-bulk-draft-published' ); ?></option>
                            <option value="id"><?php esc_html_e( 'Post ID', 'wp-bulk-draft-published' ); ?></option>
                            <option value="slug"><?php esc_html_e( 'Slug', 'wp-bulk-draft-published' ); ?></option>
                            <option value="url"><?php esc_html_e( 'Preview URL', 'wp-bulk-draft-published' ); ?></option>
                            <option value="title"><?php esc_html_e( 'Exact Title', 'wp-bulk-draft-published' ); ?></option>
                        </select>
                    </label>

                    <label>
                        <strong><?php esc_html_e( 'Rows to use', 'wp-bulk-draft-published' ); ?></strong>
                        <select id="wbdp-import-status-filter">
                            <option value="auto"><?php esc_html_e( 'Auto (prefer “No Content” when available)', 'wp-bulk-draft-published' ); ?></option>
                            <option value="none"><?php esc_html_e( 'No Content only', 'wp-bulk-draft-published' ); ?></option>
                            <option value="has"><?php esc_html_e( 'Has Content only', 'wp-bulk-draft-published' ); ?></option>
                            <option value="all"><?php esc_html_e( 'All rows in file', 'wp-bulk-draft-published' ); ?></option>
                        </select>
                    </label>
                </div>

                <div class="wbdp-import-actions">
                    <button type="button" class="button button-primary" id="wbdp-preview-import">
                        <?php esc_html_e( 'Preview File Matches', 'wp-bulk-draft-published' ); ?>
                    </button>
                    <span id="wbdp-import-help" class="description">
                        <?php esc_html_e( 'The file is analyzed first. Nothing is changed until you confirm.', 'wp-bulk-draft-published' ); ?>
                    </span>
                </div>

                <div id="wbdp-import-loading" class="wbdp-import-loading" hidden>
                    <span class="spinner is-active"></span>
                    <span><?php esc_html_e( 'Analyzing file and matching WordPress content…', 'wp-bulk-draft-published' ); ?></span>
                </div>

                <div id="wbdp-import-results" hidden>
                    <div class="wbdp-import-summary">
                        <div><span><?php esc_html_e( 'File rows', 'wp-bulk-draft-published' ); ?></span><strong id="wbdp-stat-file">0</strong></div>
                        <div><span><?php esc_html_e( 'Ready', 'wp-bulk-draft-published' ); ?></span><strong id="wbdp-stat-ready">0</strong></div>
                        <div><span><?php esc_html_e( 'Already not published', 'wp-bulk-draft-published' ); ?></span><strong id="wbdp-stat-skipped">0</strong></div>
                        <div><span><?php esc_html_e( 'Not found / mismatch', 'wp-bulk-draft-published' ); ?></span><strong id="wbdp-stat-missing">0</strong></div>
                    </div>

                    <div class="wbdp-import-toolbar">
                        <div>
                            <button type="button" class="button" id="wbdp-import-select-ready"><?php esc_html_e( 'Select All Ready', 'wp-bulk-draft-published' ); ?></button>
                            <button type="button" class="button" id="wbdp-import-clear"><?php esc_html_e( 'Clear Selection', 'wp-bulk-draft-published' ); ?></button>
                            <span id="wbdp-import-selected-count" class="wbdp-selected-count">0 selected</span>
                        </div>
                        <input type="search" id="wbdp-import-search" placeholder="<?php esc_attr_e( 'Search preview…', 'wp-bulk-draft-published' ); ?>">
                    </div>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wbdp-import-draft-form">
                        <input type="hidden" name="action" value="wbdp_import_bulk_draft">
                        <input type="hidden" name="post_type_filter" id="wbdp-import-form-post-type" value="<?php echo esc_attr( $requested_type ); ?>">
                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                        <div class="wbdp-import-table-wrap">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <td class="check-column"><input type="checkbox" id="wbdp-import-check-all" aria-label="Select all ready matches"></td>
                                        <th><?php esc_html_e( 'Result', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'File Row', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'File ID', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'Title', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'Slug', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'Matched WP Item', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'WP Status', 'wp-bulk-draft-published' ); ?></th>
                                        <th><?php esc_html_e( 'Match Method', 'wp-bulk-draft-published' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="wbdp-import-body"></tbody>
                            </table>
                        </div>

                        <div class="wbdp-import-confirm">
                            <button type="submit" class="button button-primary" id="wbdp-import-draft-selected" disabled>
                                <?php esc_html_e( 'Move Selected Matches to Draft', 'wp-bulk-draft-published' ); ?>
                            </button>
                            <span class="description">
                                <?php esc_html_e( 'Only currently published items will be changed.', 'wp-bulk-draft-published' ); ?>
                            </span>
                        </div>
                    </form>
                </div>
            </div>

                    </section>
                    <section class="wbdp-tab-panel" data-wbdp-panel="manual">
            <div class="wbdp-card">
                <h2><?php esc_html_e( 'Manual Selection', 'wp-bulk-draft-published' ); ?></h2>

                <form method="get" class="wbdp-filter-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                    <input type="hidden" name="tab" value="manual">

                    <label for="wbdp-post-type"><strong><?php esc_html_e( 'Post type', 'wp-bulk-draft-published' ); ?></strong></label>
                    <select id="wbdp-post-type" name="post_type_filter">
                        <?php foreach ( $post_types as $name => $object ) : ?>
                            <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $requested_type, $name ); ?>>
                                <?php echo esc_html( $object->labels->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="wbdp-search"><strong><?php esc_html_e( 'Search', 'wp-bulk-draft-published' ); ?></strong></label>
                    <input id="wbdp-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search title/content…', 'wp-bulk-draft-published' ); ?>">

                    <label for="wbdp-per-page"><strong><?php esc_html_e( 'Per page', 'wp-bulk-draft-published' ); ?></strong></label>
                    <select id="wbdp-per-page" name="per_page">
                        <?php foreach ( $allowed_per_page as $size ) : ?>
                            <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'wp-bulk-draft-published' ); ?></button>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wbdp-bulk-form">
                <input type="hidden" name="action" value="wbdp_bulk_draft">
                <input type="hidden" name="post_type_filter" value="<?php echo esc_attr( $requested_type ); ?>">
                <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
                <input type="hidden" name="per_page" value="<?php echo esc_attr( $per_page ); ?>">
                <input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                <div class="wbdp-toolbar">
                    <div>
                        <button type="submit" class="button button-primary" id="wbdp-draft-selected" disabled>
                            <?php esc_html_e( 'Move Selected to Draft', 'wp-bulk-draft-published' ); ?>
                        </button>
                        <span id="wbdp-selected-count" class="wbdp-selected-count"><?php esc_html_e( '0 selected', 'wp-bulk-draft-published' ); ?></span>
                    </div>
                    <div class="wbdp-total">
                        <?php
                        printf(
                            esc_html__( '%1$s published %2$s found', 'wp-bulk-draft-published' ),
                            esc_html( number_format_i18n( $query->found_posts ) ),
                            esc_html( strtolower( $post_type_object->labels->name ) )
                        );
                        ?>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped table-view-list posts">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="wbdp-select-all" aria-label="<?php esc_attr_e( 'Select all items on this page', 'wp-bulk-draft-published' ); ?>">
                            </td>
                            <th><?php esc_html_e( 'Title', 'wp-bulk-draft-published' ); ?></th>
                            <th class="wbdp-col-author"><?php esc_html_e( 'Author', 'wp-bulk-draft-published' ); ?></th>
                            <th class="wbdp-col-date"><?php esc_html_e( 'Published', 'wp-bulk-draft-published' ); ?></th>
                            <th class="wbdp-col-id"><?php esc_html_e( 'ID', 'wp-bulk-draft-published' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) : ?>
                        <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                            <?php
                            $post_id = get_the_ID();
                            $author_name = get_the_author();
                            $edit_link = get_edit_post_link( $post_id );
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input class="wbdp-post-checkbox" type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post_id ); ?>">
                                </th>
                                <td>
                                    <strong>
                                        <?php if ( $edit_link ) : ?>
                                            <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( get_the_title() ?: __( '(no title)', 'wp-bulk-draft-published' ) ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( get_the_title() ?: __( '(no title)', 'wp-bulk-draft-published' ) ); ?>
                                        <?php endif; ?>
                                    </strong>
                                </td>
                                <td class="wbdp-col-author"><?php echo esc_html( $author_name ); ?></td>
                                <td class="wbdp-col-date"><?php echo esc_html( get_the_date( 'Y-m-d H:i' ) ); ?></td>
                                <td class="wbdp-col-id"><?php echo esc_html( $post_id ); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    <?php else : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No published items found for this filter.', 'wp-bulk-draft-published' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $query->max_num_pages > 1 ) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post(
                                paginate_links(
                                    array(
                                        'base' => add_query_arg(
                                            array(
                                                'page'             => self::PAGE_SLUG,
                                                'tab'              => 'manual',
                                                'post_type_filter' => $requested_type,
                                                's'                => $search,
                                                'per_page'         => $per_page,
                                                'paged'            => '%#%',
                                            ),
                                            admin_url( 'tools.php' )
                                        ),
                                        'format'    => '',
                                        'current'   => $paged,
                                        'total'     => $query->max_num_pages,
                                        'prev_text' => '&lsaquo;',
                                        'next_text' => '&rsaquo;',
                                    )
                                )
                            );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>

            <div class="wbdp-help wbdp-card">
                <h2><?php esc_html_e( 'Also available on normal post lists', 'wp-bulk-draft-published' ); ?></h2>
                <p><?php esc_html_e( 'You can also open Posts, Pages, or a supported CPT list, select items, choose “Move published items to Draft” from Bulk actions, and click Apply.', 'wp-bulk-draft-published' ); ?></p>
            </div>
                    </section>
                </main>

                <aside class="wbdp-sidebar">
                    <div class="wbdp-card wbdp-log-dashboard">
                        <div class="wbdp-section-heading">
                            <div>
                                <h2><?php esc_html_e( 'Activity Logs', 'wp-bulk-draft-published' ); ?></h2>
                                <p class="description"><?php esc_html_e( 'Recent updates performed through this plugin.', 'wp-bulk-draft-published' ); ?></p>
                            </div>
                            <span class="wbdp-log-count"><?php echo esc_html( count( $logs ) ); ?></span>
                        </div>

                        <div class="wbdp-log-stats">
                            <div><span><?php esc_html_e( 'Today', 'wp-bulk-draft-published' ); ?></span><strong><?php echo esc_html( $today_count ); ?></strong></div>
                            <div><span><?php esc_html_e( 'Successful', 'wp-bulk-draft-published' ); ?></span><strong><?php echo esc_html( $success_count ); ?></strong></div>
                        </div>

                        <?php if ( $logs ) : ?>
                            <div class="wbdp-log-list">
                                <?php foreach ( array_slice( $logs, 0, 15 ) as $log ) : ?>
                                    <article class="wbdp-log-item">
                                        <div class="wbdp-log-item-head">
                                            <span class="wbdp-log-result wbdp-log-result-<?php echo esc_attr( $log['result'] ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $log['result'] ) ) ); ?></span>
                                            <time><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $log['timestamp'] ) ) ); ?></time>
                                        </div>
                                        <strong class="wbdp-log-title">#<?php echo esc_html( $log['post_id'] ); ?> <?php echo esc_html( $log['title'] ?: __( '(no title)', 'wp-bulk-draft-published' ) ); ?></strong>
                                        <div class="wbdp-log-meta"><span><?php echo esc_html( $log['post_type'] ); ?></span><span><?php echo esc_html( $log['source'] ); ?></span></div>
                                        <div class="wbdp-log-change"><?php echo esc_html( $log['from_status'] ); ?> <span>→</span> <?php echo esc_html( $log['to_status'] ); ?></div>
                                        <div class="wbdp-log-user"><?php printf( esc_html__( 'By %s', 'wp-bulk-draft-published' ), esc_html( $log['user_name'] ) ); ?></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wbdp-clear-log-form">
                                    <input type="hidden" name="action" value="wbdp_clear_logs">
                                    <input type="hidden" name="return_tab" id="wbdp-return-tab" value="<?php echo esc_attr( $active_tab ); ?>">
                                    <?php wp_nonce_field( 'wbdp_clear_logs_action', 'wbdp_clear_logs_nonce' ); ?>
                                    <button type="submit" class="button button-link-delete" onclick="return confirm('Clear all Bulk Draft activity logs?');"><?php esc_html_e( 'Clear activity logs', 'wp-bulk-draft-published' ); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php else : ?>
                            <div class="wbdp-empty-logs"><strong><?php esc_html_e( 'No activity yet', 'wp-bulk-draft-published' ); ?></strong><span><?php esc_html_e( 'Updates made through this plugin will appear here.', 'wp-bulk-draft-published' ); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="wbdp-card wbdp-sidebar-help">
                        <h3><?php esc_html_e( 'Logged information', 'wp-bulk-draft-published' ); ?></h3>
                        <ul>
                            <li><?php esc_html_e( 'Post/CPT ID and title', 'wp-bulk-draft-published' ); ?></li>
                            <li><?php esc_html_e( 'Action source', 'wp-bulk-draft-published' ); ?></li>
                            <li><?php esc_html_e( 'Old and resulting status', 'wp-bulk-draft-published' ); ?></li>
                            <li><?php esc_html_e( 'User and timestamp', 'wp-bulk-draft-published' ); ?></li>
                            <li><?php esc_html_e( 'Drafted, skipped, or failed result', 'wp-bulk-draft-published' ); ?></li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function ajax_import_preview() {
        check_ajax_referer( self::IMPORT_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => 'Choose a CSV or XLSX file first.' ), 400 );
        }

        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        $supported = self::get_supported_post_types();

        if ( ! isset( $supported[ $post_type ] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid or unsupported target post type.' ), 400 );
        }

        $match_method = isset( $_POST['match_method'] ) ? sanitize_key( wp_unslash( $_POST['match_method'] ) ) : 'auto';
        if ( ! in_array( $match_method, array( 'auto', 'id', 'slug', 'url', 'title' ), true ) ) {
            $match_method = 'auto';
        }

        $status_filter = isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : 'auto';
        if ( ! in_array( $status_filter, array( 'auto', 'none', 'has', 'all' ), true ) ) {
            $status_filter = 'auto';
        }

        $file = $_FILES['file'];

        if ( ! empty( $file['error'] ) ) {
            wp_send_json_error( array( 'message' => 'Upload failed with error code ' . absint( $file['error'] ) . '.' ), 400 );
        }

        if ( ! empty( $file['size'] ) && $file['size'] > 20 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => 'The import file is larger than 20 MB.' ), 400 );
        }

        $extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

        if ( 'csv' === $extension ) {
            $parsed = self::parse_csv_file( $file['tmp_name'] );
        } elseif ( 'xlsx' === $extension ) {
            $parsed = self::parse_xlsx_file( $file['tmp_name'] );
        } else {
            wp_send_json_error( array( 'message' => 'Only CSV and XLSX files are supported.' ), 400 );
        }

        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( array( 'message' => $parsed->get_error_message() ), 400 );
        }

        if ( empty( $parsed['rows'] ) ) {
            wp_send_json_error( array( 'message' => 'No data rows were found in the file.' ), 400 );
        }

        $rows = self::filter_import_rows( $parsed['rows'], $status_filter );

        if ( empty( $rows ) ) {
            wp_send_json_error( array( 'message' => 'No rows remain after applying the selected file-row filter.' ), 400 );
        }

        if ( count( $rows ) > 20000 ) {
            wp_send_json_error( array( 'message' => 'This file contains more than 20,000 usable rows. Split it into smaller files.' ), 400 );
        }

        $preview = array();
        $stats   = array(
            'file_rows' => count( $rows ),
            'ready'     => 0,
            'skipped'   => 0,
            'missing'   => 0,
        );

        foreach ( $rows as $index => $row ) {
            $matched = self::match_import_row( $row, $post_type, $match_method );

            $entry = array(
                'row'          => $index + 2,
                'file_id'      => self::row_value( $row, array( 'id', 'post id', 'post_id', 'wp id', 'wordpress id' ) ),
                'file_title'   => self::row_value( $row, array( 'title', 'post title', 'name' ) ),
                'file_slug'    => self::row_value( $row, array( 'slug', 'post slug', 'post_name' ) ),
                'file_cpt'     => self::row_value( $row, array( 'cpt', 'post type', 'post_type', 'type' ) ),
                'post_id'      => 0,
                'post_title'   => '',
                'post_slug'    => '',
                'post_status'  => '',
                'edit_link'    => '',
                'match_method' => $matched['method'],
                'result'       => $matched['result'],
                'actionable'   => false,
            );

            if ( ! empty( $matched['post'] ) && $matched['post'] instanceof WP_Post ) {
                $post = $matched['post'];

                $entry['post_id']     = (int) $post->ID;
                $entry['post_title']  = get_the_title( $post );
                $entry['post_slug']   = $post->post_name;
                $entry['post_status'] = $post->post_status;
                $entry['edit_link']   = get_edit_post_link( $post->ID, 'raw' ) ?: '';

                if ( 'publish' === $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
                    $entry['actionable'] = true;
                    $entry['result']     = 'ready';
                    $stats['ready']++;
                } else {
                    $entry['result'] = 'not_published';
                    $stats['skipped']++;
                }
            } else {
                $stats['missing']++;
            }

            $preview[] = $entry;
        }

        wp_send_json_success(
            array(
                'columns' => $parsed['headers'],
                'rows'    => $preview,
                'stats'   => $stats,
                'sheet'   => isset( $parsed['sheet'] ) ? $parsed['sheet'] : '',
            )
        );
    }

    private static function normalize_header( $header ) {
        $header = strtolower( trim( (string) $header ) );
        $header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
        $header = str_replace( array( '-', '_' ), ' ', $header );
        return trim( preg_replace( '/\s+/', ' ', $header ) );
    }

    private static function normalize_row( $headers, $values ) {
        $row = array();

        foreach ( $headers as $i => $header ) {
            $key         = self::normalize_header( $header );
            $row[ $key ] = isset( $values[ $i ] ) ? trim( (string) $values[ $i ] ) : '';
        }

        return $row;
    }

    private static function parse_csv_file( $path ) {
        $handle = fopen( $path, 'rb' );

        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', 'Unable to open the CSV file.' );
        }

        $headers = fgetcsv( $handle );

        if ( false === $headers || empty( $headers ) ) {
            fclose( $handle );
            return new WP_Error( 'csv_headers', 'The CSV file does not contain a header row.' );
        }

        $rows = array();

        while ( false !== ( $values = fgetcsv( $handle ) ) ) {
            if ( ! array_filter( $values, 'strlen' ) ) {
                continue;
            }

            $rows[] = self::normalize_row( $headers, $values );
        }

        fclose( $handle );

        return array(
            'headers' => array_values( array_map( array( __CLASS__, 'normalize_header' ), $headers ) ),
            'rows'    => $rows,
            'sheet'   => '',
        );
    }

    private static function parse_xlsx_file( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'xlsx_zip', 'XLSX import requires PHP ZipArchive (php-zip). You can use CSV instead.' );
        }

        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'xlsx_xml', 'XLSX import requires PHP SimpleXML. You can use CSV instead.' );
        }

        $zip = new ZipArchive();

        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'xlsx_open', 'Unable to open the XLSX file.' );
        }

        $shared_strings = array();
        $shared_xml     = $zip->getFromName( 'xl/sharedStrings.xml' );

        if ( false !== $shared_xml ) {
            $xml = simplexml_load_string( $shared_xml );

            if ( $xml ) {
                $xml->registerXPathNamespace( 'a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

                foreach ( $xml->xpath( '//a:si' ) as $si ) {
                    $parts = array();

                    if ( isset( $si->t ) ) {
                        $parts[] = (string) $si->t;
                    }

                    foreach ( $si->r as $run ) {
                        if ( isset( $run->t ) ) {
                            $parts[] = (string) $run->t;
                        }
                    }

                    $shared_strings[] = implode( '', $parts );
                }
            }
        }

        $sheet_path  = 'xl/worksheets/sheet1.xml';
        $sheet_name  = 'Sheet1';
        $workbook    = $zip->getFromName( 'xl/workbook.xml' );

        if ( false !== $workbook ) {
            $wb = simplexml_load_string( $workbook );
            if ( $wb ) {
                $wb->registerXPathNamespace( 'a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
                $sheets = $wb->xpath( '//a:sheets/a:sheet' );
                if ( ! empty( $sheets[0] ) ) {
                    $sheet_name = (string) $sheets[0]['name'];
                }
            }
        }

        $sheet_xml = $zip->getFromName( $sheet_path );

        if ( false === $sheet_xml ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet', 'The first worksheet could not be read.' );
        }

        $xml = simplexml_load_string( $sheet_xml );

        if ( ! $xml ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet_xml', 'The worksheet XML is invalid.' );
        }

        $xml->registerXPathNamespace( 'a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        $sheet_rows = $xml->xpath( '//a:sheetData/a:row' );
        $matrix     = array();

        foreach ( $sheet_rows as $row ) {
            $cells = array();

            foreach ( $row->c as $cell ) {
                $ref   = (string) $cell['r'];
                $col   = preg_replace( '/\d+/', '', $ref );
                $index = self::xlsx_column_index( $col );
                $type  = (string) $cell['t'];
                $value = '';

                if ( 's' === $type ) {
                    $shared_index = isset( $cell->v ) ? (int) $cell->v : -1;
                    $value        = isset( $shared_strings[ $shared_index ] ) ? $shared_strings[ $shared_index ] : '';
                } elseif ( 'inlineStr' === $type ) {
                    if ( isset( $cell->is->t ) ) {
                        $value = (string) $cell->is->t;
                    } else {
                        $parts = array();
                        foreach ( $cell->is->r as $run ) {
                            if ( isset( $run->t ) ) {
                                $parts[] = (string) $run->t;
                            }
                        }
                        $value = implode( '', $parts );
                    }
                } else {
                    $value = isset( $cell->v ) ? (string) $cell->v : '';
                }

                $cells[ $index ] = $value;
            }

            if ( empty( $cells ) ) {
                continue;
            }

            $max    = max( array_keys( $cells ) );
            $values = array();

            for ( $i = 0; $i <= $max; $i++ ) {
                $values[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
            }

            $matrix[] = $values;
        }

        $zip->close();

        if ( count( $matrix ) < 2 ) {
            return new WP_Error( 'xlsx_empty', 'The first XLSX worksheet does not contain data rows.' );
        }

        $headers = array_shift( $matrix );
        $rows    = array();

        foreach ( $matrix as $values ) {
            if ( ! array_filter( $values, 'strlen' ) ) {
                continue;
            }

            $rows[] = self::normalize_row( $headers, $values );
        }

        return array(
            'headers' => array_values( array_map( array( __CLASS__, 'normalize_header' ), $headers ) ),
            'rows'    => $rows,
            'sheet'   => $sheet_name,
        );
    }

    private static function xlsx_column_index( $letters ) {
        $letters = strtoupper( preg_replace( '/[^A-Z]/i', '', (string) $letters ) );
        $index   = 0;

        for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
        }

        return max( 0, $index - 1 );
    }

    private static function row_value( $row, $aliases ) {
        foreach ( $aliases as $alias ) {
            $key = self::normalize_header( $alias );
            if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
                return trim( (string) $row[ $key ] );
            }
        }

        return '';
    }

    private static function filter_import_rows( $rows, $status_filter ) {
        $has_status_column = false;

        foreach ( $rows as $row ) {
            if ( '' !== self::row_value( $row, array( 'content status', 'status' ) ) ) {
                $has_status_column = true;
                break;
            }
        }

        if ( 'auto' === $status_filter ) {
            $status_filter = $has_status_column ? 'none' : 'all';
        }

        if ( 'all' === $status_filter ) {
            return $rows;
        }

        $wanted = 'none' === $status_filter ? 'no content' : 'has content';

        return array_values(
            array_filter(
                $rows,
                function( $row ) use ( $wanted ) {
                    $value = strtolower( self::row_value( $row, array( 'content status', 'status' ) ) );
                    return $wanted === trim( $value );
                }
            )
        );
    }

    private static function match_import_row( $row, $post_type, $match_method ) {
        $methods = 'auto' === $match_method
            ? array( 'id', 'slug', 'url', 'title' )
            : array( $match_method );

        foreach ( $methods as $method ) {
            if ( 'id' === $method ) {
                $value = self::row_value( $row, array( 'id', 'post id', 'post_id', 'wp id', 'wordpress id' ) );
                $id    = absint( $value );

                if ( $id ) {
                    $post = get_post( $id );

                    if ( $post ) {
                        if ( $post_type !== $post->post_type ) {
                            return array( 'post' => null, 'method' => 'Post ID', 'result' => 'wrong_cpt' );
                        }

                        return array( 'post' => $post, 'method' => 'Post ID', 'result' => 'matched' );
                    }
                }
            }

            if ( 'slug' === $method ) {
                $slug = sanitize_title(
                    self::row_value( $row, array( 'slug', 'post slug', 'post_name' ) )
                );

                if ( $slug ) {
                    $posts = get_posts(
                        array(
                            'post_type'      => $post_type,
                            'post_status'    => 'any',
                            'name'           => $slug,
                            'posts_per_page' => 2,
                            'no_found_rows'  => true,
                        )
                    );

                    if ( 1 === count( $posts ) ) {
                        return array( 'post' => $posts[0], 'method' => 'Slug', 'result' => 'matched' );
                    }

                    if ( count( $posts ) > 1 ) {
                        return array( 'post' => null, 'method' => 'Slug', 'result' => 'ambiguous' );
                    }
                }
            }

            if ( 'url' === $method ) {
                $url = self::row_value(
                    $row,
                    array( 'preview url', 'url', 'post url', 'permalink', 'link' )
                );

                if ( $url ) {
                    $id = url_to_postid( $url );

                    if ( $id ) {
                        $post = get_post( $id );

                        if ( $post && $post_type === $post->post_type ) {
                            return array( 'post' => $post, 'method' => 'Preview URL', 'result' => 'matched' );
                        }

                        if ( $post ) {
                            return array( 'post' => null, 'method' => 'Preview URL', 'result' => 'wrong_cpt' );
                        }
                    }

                    $path = wp_parse_url( $url, PHP_URL_PATH );
                    if ( $path ) {
                        $slug = sanitize_title( basename( untrailingslashit( $path ) ) );

                        if ( $slug ) {
                            $posts = get_posts(
                                array(
                                    'post_type'      => $post_type,
                                    'post_status'    => 'any',
                                    'name'           => $slug,
                                    'posts_per_page' => 2,
                                    'no_found_rows'  => true,
                                )
                            );

                            if ( 1 === count( $posts ) ) {
                                return array( 'post' => $posts[0], 'method' => 'Preview URL → Slug', 'result' => 'matched' );
                            }
                        }
                    }
                }
            }

            if ( 'title' === $method ) {
                global $wpdb;

                $title = self::row_value( $row, array( 'title', 'post title', 'name' ) );

                if ( $title ) {
                    $ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                            WHERE post_type = %s
                            AND post_title = %s
                            AND post_status NOT IN ('trash','auto-draft','inherit')
                            LIMIT 2",
                            $post_type,
                            $title
                        )
                    );

                    if ( 1 === count( $ids ) ) {
                        return array( 'post' => get_post( (int) $ids[0] ), 'method' => 'Exact Title', 'result' => 'matched' );
                    }

                    if ( count( $ids ) > 1 ) {
                        return array( 'post' => null, 'method' => 'Exact Title', 'result' => 'ambiguous' );
                    }
                }
            }
        }

        return array( 'post' => null, 'method' => '', 'result' => 'not_found' );
    }

    public static function handle_import_bulk_draft() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'wp-bulk-draft-published' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['post_ids'] ) )
            : array();

        $result = self::draft_posts( $post_ids, 'File Import' );

        $post_type_filter = isset( $_POST['post_type_filter'] )
            ? sanitize_key( wp_unslash( $_POST['post_type_filter'] ) )
            : 'post';

        $redirect = add_query_arg(
            array(
                'page'             => self::PAGE_SLUG,
                'post_type_filter' => $post_type_filter,
                'tab'              => 'file',
                'wbdp_drafted'     => $result['drafted'],
                'wbdp_skipped'     => $result['skipped'],
                'wbdp_failed'      => $result['failed'],
            ),
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_tools_bulk_draft() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'wp-bulk-draft-published' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['post_ids'] ) )
            : array();

        $result = self::draft_posts( $post_ids, 'Manual Selection' );

        $post_type_filter = isset( $_POST['post_type_filter'] ) ? sanitize_key( wp_unslash( $_POST['post_type_filter'] ) ) : 'post';
        $search           = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
        $per_page         = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50;
        $paged            = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

        $redirect = add_query_arg(
            array(
                'page'             => self::PAGE_SLUG,
                'post_type_filter' => $post_type_filter,
                'tab'              => 'manual',
                's'                => $search,
                'per_page'         => $per_page,
                'paged'            => $paged,
                'wbdp_drafted'     => $result['drafted'],
                'wbdp_skipped'     => $result['skipped'],
                'wbdp_failed'      => $result['failed'],
            ),
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function draft_posts( $post_ids, $source = 'Manual Selection' ) {
        $result = array( 'drafted' => 0, 'skipped' => 0, 'failed' => 0 );
        $supported = self::get_supported_post_types();
        $post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );

            if ( ! $post || ! isset( $supported[ $post->post_type ] ) ) {
                $result['skipped']++;
                continue;
            }

            $base_log = array(
                'post_id' => $post_id,
                'title' => get_the_title( $post ),
                'post_type' => $post->post_type,
                'source' => $source,
                'from_status' => $post->post_status,
            );

            if ( ! current_user_can( 'edit_post', $post_id ) || 'publish' !== $post->post_status ) {
                $result['skipped']++;
                self::add_log( array_merge( $base_log, array( 'to_status' => $post->post_status, 'result' => 'skipped' ) ) );
                continue;
            }

            $updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );

            if ( is_wp_error( $updated ) || ! $updated ) {
                $result['failed']++;
                self::add_log( array_merge( $base_log, array( 'to_status' => $post->post_status, 'result' => 'failed' ) ) );
            } else {
                $result['drafted']++;
                self::add_log( array_merge( $base_log, array( 'to_status' => 'draft', 'result' => 'drafted' ) ) );
            }
        }

        return $result;
    }

    private static function get_logs() {
        $logs = get_option( self::LOG_OPTION, array() );
        return is_array( $logs ) ? $logs : array();
    }

    private static function add_log( $entry ) {
        $logs = self::get_logs();
        $user = wp_get_current_user();
        $entry = wp_parse_args( $entry, array( 'post_id'=>0, 'title'=>'', 'post_type'=>'', 'source'=>'', 'from_status'=>'', 'to_status'=>'', 'result'=>'' ) );
        array_unshift( $logs, array(
            'timestamp' => current_time( 'mysql' ),
            'user_id' => get_current_user_id(),
            'user_name' => $user && $user->exists() ? $user->display_name : __( 'Unknown user', 'wp-bulk-draft-published' ),
            'post_id' => absint( $entry['post_id'] ),
            'title' => sanitize_text_field( $entry['title'] ),
            'post_type' => sanitize_key( $entry['post_type'] ),
            'source' => sanitize_text_field( $entry['source'] ),
            'from_status' => sanitize_key( $entry['from_status'] ),
            'to_status' => sanitize_key( $entry['to_status'] ),
            'result' => sanitize_key( $entry['result'] ),
        ) );
        update_option( self::LOG_OPTION, array_slice( $logs, 0, self::MAX_LOGS ), false );
    }

    public static function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to clear activity logs.', 'wp-bulk-draft-published' ) );
        }
        check_admin_referer( 'wbdp_clear_logs_action', 'wbdp_clear_logs_nonce' );
        delete_option( self::LOG_OPTION );
        $tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'file';
        if ( ! in_array( $tab, array( 'file', 'manual' ), true ) ) $tab = 'file';
        wp_safe_redirect( add_query_arg( array( 'page'=>self::PAGE_SLUG, 'tab'=>$tab ), admin_url( 'tools.php' ) ) );
        exit;
    }
}

WBDP_Bulk_Draft_Published::init();

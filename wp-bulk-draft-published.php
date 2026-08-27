<?php
/**
 * Plugin Name: Bulk Draft Published Posts
 * Description: Safely move published Posts, Pages, and supported custom post types to Draft in bulk. Includes a live-search Tools screen and native list-table bulk action.
 * Version: 1.1.0
 * Author: Custom Utility
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-bulk-draft-published
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WBDP_Bulk_Draft_Published {
    const VERSION = '1.1.0';
    const PAGE_SLUG = 'wbdp-bulk-draft';
    const NONCE_ACTION = 'wbdp_bulk_draft_action';
    const NONCE_NAME = 'wbdp_nonce';
    const AJAX_NONCE_ACTION = 'wbdp_live_search';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_wbdp_bulk_draft', array( __CLASS__, 'handle_tools_bulk_draft' ) );
        add_action( 'wp_ajax_wbdp_live_search', array( __CLASS__, 'ajax_live_search' ) );
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

        $excluded = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
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
            'WBDP_DATA',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::AJAX_NONCE_ACTION ),
                'strings' => array(
                    'searching'       => __( 'Searching…', 'wp-bulk-draft-published' ),
                    'error'           => __( 'Unable to load results. Please try again.', 'wp-bulk-draft-published' ),
                    'selected'        => __( 'selected', 'wp-bulk-draft-published' ),
                    'confirmSingular' => __( 'Move 1 selected published item to Draft?', 'wp-bulk-draft-published' ),
                    'confirmPlural'   => __( 'Move %d selected published items to Draft?', 'wp-bulk-draft-published' ),
                ),
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

        $result = self::draft_posts( $post_ids );

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

    private static function sanitize_filters( $source ) {
        $post_types = self::get_supported_post_types();

        $requested_type = isset( $source['post_type_filter'] ) ? sanitize_key( wp_unslash( $source['post_type_filter'] ) ) : 'post';
        if ( ! isset( $post_types[ $requested_type ] ) ) {
            $requested_type = array_key_first( $post_types );
        }

        $search   = isset( $source['s'] ) ? sanitize_text_field( wp_unslash( $source['s'] ) ) : '';
        $paged    = isset( $source['paged'] ) ? max( 1, absint( $source['paged'] ) ) : 1;
        $per_page = isset( $source['per_page'] ) ? absint( $source['per_page'] ) : 50;
        $allowed_per_page = array( 25, 50, 100, 200 );

        if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
            $per_page = 50;
        }

        return array(
            'post_types'       => $post_types,
            'post_type'        => $requested_type,
            'search'           => $search,
            'paged'            => $paged,
            'per_page'         => $per_page,
            'allowed_per_page' => $allowed_per_page,
        );
    }

    private static function get_published_query( $post_type, $search, $per_page, $paged ) {
        return new WP_Query(
            array(
                'post_type'              => $post_type,
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
    }

    private static function render_rows( $query ) {
        ob_start();

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $post_id     = (int) $post->ID;
                $author_name = get_the_author_meta( 'display_name', $post->post_author );
                $edit_link   = get_edit_post_link( $post_id );
                $title       = get_the_title( $post_id );
                $title       = '' !== $title ? $title : __( '(no title)', 'wp-bulk-draft-published' );
                ?>
                <tr data-post-id="<?php echo esc_attr( $post_id ); ?>">
                    <th scope="row" class="check-column">
                        <input class="wbdp-post-checkbox" type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post_id ); ?>">
                    </th>
                    <td>
                        <strong>
                            <?php if ( $edit_link ) : ?>
                                <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $title ); ?>
                            <?php endif; ?>
                        </strong>
                    </td>
                    <td class="wbdp-col-author"><?php echo esc_html( $author_name ); ?></td>
                    <td class="wbdp-col-date"><?php echo esc_html( get_the_date( 'Y-m-d H:i', $post_id ) ); ?></td>
                    <td class="wbdp-col-id"><?php echo esc_html( $post_id ); ?></td>
                </tr>
                <?php
            }
        } else {
            ?>
            <tr class="wbdp-empty-row">
                <td colspan="5"><?php esc_html_e( 'No published items found for this filter.', 'wp-bulk-draft-published' ); ?></td>
            </tr>
            <?php
        }

        return ob_get_clean();
    }

    private static function get_total_label( $query, $post_type_object ) {
        return sprintf(
            __( '%1$s published %2$s found', 'wp-bulk-draft-published' ),
            number_format_i18n( $query->found_posts ),
            strtolower( $post_type_object->labels->name )
        );
    }

    private static function render_pagination( $query, $requested_type, $search, $per_page, $paged ) {
        if ( $query->max_num_pages <= 1 ) {
            return '';
        }

        return (string) paginate_links(
            array(
                'base'      => add_query_arg(
                    array(
                        'page'             => self::PAGE_SLUG,
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
        );
    }

    public static function ajax_live_search() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-bulk-draft-published' ) ), 403 );
        }

        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        $filters = self::sanitize_filters( $_POST );
        $post_type_object = $filters['post_types'][ $filters['post_type'] ];
        $query = self::get_published_query( $filters['post_type'], $filters['search'], $filters['per_page'], $filters['paged'] );

        // If the current page no longer exists after filtering, return the first page.
        if ( $query->max_num_pages > 0 && $filters['paged'] > $query->max_num_pages ) {
            $filters['paged'] = 1;
            $query = self::get_published_query( $filters['post_type'], $filters['search'], $filters['per_page'], 1 );
        }

        wp_send_json_success(
            array(
                'rows'       => self::render_rows( $query ),
                'total'      => self::get_total_label( $query, $post_type_object ),
                'pagination' => self::render_pagination( $query, $filters['post_type'], $filters['search'], $filters['per_page'], $filters['paged'] ),
                'paged'      => $filters['paged'],
                'foundPosts' => (int) $query->found_posts,
            )
        );
    }

    public static function render_tools_page() {
        $post_types = self::get_supported_post_types();

        if ( empty( $post_types ) ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Draft Published Posts', 'wp-bulk-draft-published' ) . '</h1>';
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No editable post types are available for your account.', 'wp-bulk-draft-published' ) . '</p></div></div>';
            return;
        }

        $filters = self::sanitize_filters( $_GET );
        $requested_type = $filters['post_type'];
        $search = $filters['search'];
        $paged = $filters['paged'];
        $per_page = $filters['per_page'];
        $allowed_per_page = $filters['allowed_per_page'];
        $query = self::get_published_query( $requested_type, $search, $per_page, $paged );
        $post_type_object = $post_types[ $requested_type ];
        ?>
        <div class="wrap wbdp-wrap">
            <h1><?php esc_html_e( 'Bulk Draft Published Posts', 'wp-bulk-draft-published' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Start typing in Search and the published results update automatically. Select items across searches, then move them to Draft together.', 'wp-bulk-draft-published' ); ?>
            </p>

            <div class="wbdp-card">
                <form method="get" class="wbdp-filter-form" id="wbdp-filter-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

                    <label for="wbdp-post-type"><strong><?php esc_html_e( 'Post type', 'wp-bulk-draft-published' ); ?></strong></label>
                    <select id="wbdp-post-type" name="post_type_filter">
                        <?php foreach ( $post_types as $name => $object ) : ?>
                            <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $requested_type, $name ); ?>>
                                <?php echo esc_html( $object->labels->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="wbdp-search"><strong><?php esc_html_e( 'Search', 'wp-bulk-draft-published' ); ?></strong></label>
                    <div class="wbdp-search-wrap">
                        <input id="wbdp-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Start typing a title or keyword…', 'wp-bulk-draft-published' ); ?>" autocomplete="off">
                        <span class="spinner" id="wbdp-search-spinner" aria-hidden="true"></span>
                    </div>

                    <label for="wbdp-per-page"><strong><?php esc_html_e( 'Per page', 'wp-bulk-draft-published' ); ?></strong></label>
                    <select id="wbdp-per-page" name="per_page">
                        <?php foreach ( $allowed_per_page as $size ) : ?>
                            <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button wbdp-fallback-filter"><?php esc_html_e( 'Filter', 'wp-bulk-draft-published' ); ?></button>
                    <span class="wbdp-live-hint"><?php esc_html_e( 'Live search is automatic.', 'wp-bulk-draft-published' ); ?></span>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wbdp-bulk-form">
                <input type="hidden" name="action" value="wbdp_bulk_draft">
                <input type="hidden" id="wbdp-bulk-post-type" name="post_type_filter" value="<?php echo esc_attr( $requested_type ); ?>">
                <input type="hidden" id="wbdp-bulk-search" name="s" value="<?php echo esc_attr( $search ); ?>">
                <input type="hidden" id="wbdp-bulk-per-page" name="per_page" value="<?php echo esc_attr( $per_page ); ?>">
                <input type="hidden" id="wbdp-bulk-paged" name="paged" value="<?php echo esc_attr( $paged ); ?>">
                <div id="wbdp-persistent-selection"></div>
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                <div class="wbdp-toolbar">
                    <div class="wbdp-actions">
                        <button type="submit" class="button button-primary" id="wbdp-draft-selected" disabled>
                            <?php esc_html_e( 'Move Selected to Draft', 'wp-bulk-draft-published' ); ?>
                        </button>
                        <button type="button" class="button" id="wbdp-clear-selected" disabled><?php esc_html_e( 'Clear Selection', 'wp-bulk-draft-published' ); ?></button>
                        <span id="wbdp-selected-count" class="wbdp-selected-count"><?php esc_html_e( '0 selected', 'wp-bulk-draft-published' ); ?></span>
                    </div>
                    <div class="wbdp-total" id="wbdp-total" aria-live="polite">
                        <?php echo esc_html( self::get_total_label( $query, $post_type_object ) ); ?>
                    </div>
                </div>

                <div class="wbdp-results" id="wbdp-results" aria-busy="false">
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
                        <tbody id="wbdp-results-body">
                            <?php echo self::render_rows( $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </tbody>
                    </table>

                    <div class="tablenav bottom wbdp-pagination-wrap" id="wbdp-pagination-wrap">
                        <div class="tablenav-pages" id="wbdp-pagination">
                            <?php echo wp_kses_post( self::render_pagination( $query, $requested_type, $search, $per_page, $paged ) ); ?>
                        </div>
                    </div>
                </div>
            </form>

            <div class="wbdp-help wbdp-card">
                <h2><?php esc_html_e( 'Also available on normal post lists', 'wp-bulk-draft-published' ); ?></h2>
                <p><?php esc_html_e( 'You can also open Posts, Pages, or a supported CPT list, select items, choose “Move published items to Draft” from Bulk actions, and click Apply.', 'wp-bulk-draft-published' ); ?></p>
            </div>
        </div>
        <?php
    }

    public static function handle_tools_bulk_draft() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'wp-bulk-draft-published' ) );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

        $post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['post_ids'] ) )
            : array();

        $result = self::draft_posts( $post_ids );

        $post_type_filter = isset( $_POST['post_type_filter'] ) ? sanitize_key( wp_unslash( $_POST['post_type_filter'] ) ) : 'post';
        $search           = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
        $per_page         = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50;
        $paged            = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

        $redirect = add_query_arg(
            array(
                'page'             => self::PAGE_SLUG,
                'post_type_filter' => $post_type_filter,
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

    private static function draft_posts( $post_ids ) {
        $result = array(
            'drafted' => 0,
            'skipped' => 0,
            'failed'  => 0,
        );

        $supported = self::get_supported_post_types();
        $post_ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );

            if ( ! $post || ! isset( $supported[ $post->post_type ] ) ) {
                $result['skipped']++;
                continue;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                $result['skipped']++;
                continue;
            }

            if ( 'publish' !== $post->post_status ) {
                $result['skipped']++;
                continue;
            }

            $updated = wp_update_post(
                array(
                    'ID'          => $post_id,
                    'post_status' => 'draft',
                ),
                true
            );

            if ( is_wp_error( $updated ) || ! $updated ) {
                $result['failed']++;
            } else {
                $result['drafted']++;
            }
        }

        return $result;
    }
}

WBDP_Bulk_Draft_Published::init();

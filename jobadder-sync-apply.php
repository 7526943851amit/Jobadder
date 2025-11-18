<?php

/**
 * Plugin Name: JobAdder Sync + Apply + MailerLite
 * Description: Sync JobAdder job ads into WP (custom post type), show job list/single pages, add an apply form that saves applications and subscribes applicants to MailerLite. Exposes /feed/jobadder-jobs for MailerLite RSS campaigns.
 * Version: 1.0.0
 * Author: Gig Soft
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JobAdder_Sync_Apply {

    private $option_name = 'jobadder_sync_apply_settings';
    private $token_option = 'jobadder_sync_apply_tokens';
    private $cron_hook = 'jobadder_sync_apply_cron';

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'on_deactivate' ] );

        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'init', [ $this, 'register_feed' ] );

        // REST webhook
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
   // Cron schedule filter - YEHA PEHLE ADD KARNA HAI
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        // Polling cron
        add_action( $this->cron_hook, [ $this, 'fetch_and_upsert_jobs' ] );

        // Append apply form to job content
        add_filter( 'the_content', [ $this, 'maybe_append_apply_form' ] );

        // Form handler
        add_action( 'admin_post_nopriv_jobadder_apply', [ $this, 'handle_apply_form' ] );
        add_action( 'admin_post_jobadder_apply', [ $this, 'handle_apply_form' ] );
             add_action( 'wp_ajax_debug_cron_status', [ $this, 'debug_cron_status' ] );
    }

    /* ------------------ Activation / Deactivation ------------------ */
    public function on_activate() {
         $this->add_cron_schedule( [] );

        if ( ! wp_next_scheduled( $this->cron_hook ) ) {
            wp_schedule_event( time(), 'ten_minutes', $this->cron_hook );
            error_log( '[JobAdder] Cron job scheduled on activation' );
        }
        $this->register_post_types();
        flush_rewrite_rules();
    }

    public function on_deactivate() {
        wp_clear_scheduled_hook( $this->cron_hook );
        flush_rewrite_rules();
          error_log( '[JobAdder] Cron job cleared on deactivation' );
    }

    /* ------------------ Register a custom schedule (10 minutes) ------------------ */
    public function add_cron_schedule( $schedules ) {
        if (!isset($schedules['ten_minutes'])) {
            $schedules['ten_minutes'] = [
                'interval' => 10 * 60,
                'display'  => __( 'Every 10 Minutes' )
            ];
        }
        return $schedules;
    }

    /* ------------------ Post Types ------------------ */
 public function register_post_types() {

    // -------------------------
    // JOBS POST TYPE
    // -------------------------
    $job_labels = [
        'name'                  => 'Jobs',
        'singular_name'         => 'Job',
        'menu_name'             => 'Jobs',
        'name_admin_bar'        => 'Job',
        'add_new'               => 'Add New',
        'add_new_item'          => 'Add New Job',
        'new_item'              => 'New Job',
        'edit_item'             => 'Edit Job',
        'view_item'             => 'View Job',
        'all_items'             => 'All Jobs',
        'search_items'          => 'Search Jobs',
        'not_found'             => 'No jobs found.',
        'not_found_in_trash'    => 'No jobs found in Trash.',
    ];

    $job_args = [
        'labels'             => $job_labels,
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => [ 'slug' => 'jobs' ],
        'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'comments' ],
        'show_in_rest'       => true,
        'menu_position'      => 5,
        'menu_icon'          => 'dashicons-businessman',
        'capability_type'    => 'post',
        'taxonomies'         => ['job_category', 'post_tag'], // tags + custom taxonomy
    ];

    register_post_type( 'job', $job_args );

    // -------------------------
    // JOB CATEGORIES (Custom Taxonomy)
    // -------------------------
    $job_category_labels = [
        'name'              => 'Job Categories',
        'singular_name'     => 'Job Category',
        'search_items'      => 'Search Job Categories',
        'all_items'         => 'All Job Categories',
        'parent_item'       => 'Parent Category',
        'parent_item_colon' => 'Parent Category:',
        'edit_item'         => 'Edit Category',
        'update_item'       => 'Update Category',
        'add_new_item'      => 'Add New Category',
        'new_item_name'     => 'New Category Name',
        'menu_name'         => 'Job Categories',
    ];

    register_taxonomy(
        'job_category',
        'job',
        [
            'labels'        => $job_category_labels,
            'hierarchical'  => true,
            'show_ui'       => true,
            'show_in_rest'  => true,
            'rewrite'       => [ 'slug' => 'job-category' ],
        ]
    );

    add_filter( 'manage_job_posts_columns', function( $columns ) {
    $columns['job_category'] = __( 'Categories', 'textdomain' );
    return $columns;
});
// Show categories in that column
add_action( 'manage_job_posts_custom_column', function( $column, $post_id ) {
    if ( $column === 'job_category' ) {
        $terms = get_the_terms( $post_id, 'job_category' );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $term_links = array_map( function( $term ) {
                return sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( admin_url( 'edit.php?post_type=job&job_category=' . $term->slug ) ),
                    esc_html( $term->name )
                );
            }, $terms );
            echo implode( ', ', $term_links );
        } else {
            echo '—';
        }
    }
}, 10, 2 );

    // -------------------------
    // JOB APPLICATION POST TYPE
    // -------------------------
    $application_labels = [
        'name'          => 'Applications',
        'singular_name' => 'Application',
        'menu_name'     => 'Applications',
        'add_new_item'  => 'Add New Application',
        'edit_item'     => 'Edit Application',
    ];

    register_post_type( 'job_application', [
        'labels' => $application_labels,
        'public' => false,
        'show_ui' => true,
        'supports' => [ 'title', 'editor', 'custom-fields' ],
    ] );

}



    /* ------------------ Admin Menu & Settings ------------------ */
    public function add_admin_menu() {
        add_menu_page( 'JobAdder Sync', 'JobAdder Sync', 'manage_options', 'jobadder-sync', [ $this, 'settings_page' ], 'dashicons-admin-network', 80 );
    }

    public function register_settings() {
        register_setting( 'jobadder_sync_group', $this->option_name, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ] );
    }

    public function sanitize_settings( $input ) {
        $out = [];
        $out['client_id']        = sanitize_text_field( $input['client_id'] ?? '' );
        $out['client_secret']    = sanitize_text_field( $input['client_secret'] ?? '' );
        $out['redirect_uri']     = esc_url_raw( $input['redirect_uri'] ?? admin_url( 'admin.php?page=jobadder-sync' ) );
        $out['jobboard_id']      = sanitize_text_field( $input['jobboard_id'] ?? '' );
        $out['mailerlite_key']   = sanitize_text_field( $input['mailerlite_key'] ?? '' );
        $out['mailerlite_group_id'] = sanitize_text_field( $input['mailerlite_group_id'] ?? '' );
        return $out;
    }

    public function settings_page() {
        $opts = get_option( $this->option_name, [] );
        $tokens = get_option( $this->token_option, [] );
        ?>
        <div class="wrap">
            <h1>JobAdder Sync & Apply Settings</h1>

              <!-- Cron Status Debug Section -->
            <div style="background: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #0073aa;">
                <h3>Cron Job Status</h3>
                <?php
                $next_scheduled = wp_next_scheduled( $this->cron_hook );
                $cron_enabled = $next_scheduled !== false;
                
                echo '<p><strong>Status:</strong> ' . ($cron_enabled ? 
                    '<span style="color: green;">✅ Enabled</span>' : 
                    '<span style="color: red;">❌ Disabled</span>') . '</p>';
                
                if ($cron_enabled) {
                    echo '<p><strong>Next Run:</strong> ' . date('Y-m-d H:i:s', $next_scheduled) . ' (' . human_time_diff($next_scheduled) . ' from now)</p>';
                } else {
                    echo '<p><strong>Next Run:</strong> Not scheduled</p>';
                }
                
                echo '<p><strong>Last Run Log:</strong> ' . get_option('jobadder_last_cron_run', 'Never') . '</p>';
                ?>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=jobadder-sync&force_cron=1' ) ); ?>" class="button">Run Cron Manually</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=jobadder-sync&reschedule_cron=1' ) ); ?>" class="button">Reschedule Cron</a>
                </p>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields( 'jobadder_sync_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th>JobAdder Client ID</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[client_id]" value="<?php echo esc_attr( $opts['client_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>JobAdder Client Secret</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[client_secret]" value="<?php echo esc_attr( $opts['client_secret'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Redirect URI</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[redirect_uri]" value="<?php echo esc_attr( $opts['redirect_uri'] ?? admin_url( 'admin.php?page=jobadder-sync' ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>Job Board ID</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[jobboard_id]" value="<?php echo esc_attr( $opts['jobboard_id'] ?? '' ); ?>" class="regular-text" />
                        <p class="description">Enter the Job Board ID to fetch ads from (see JobAdder admin or support).</p></td>
                    </tr>
                    <tr>
                        <th>MailerLite API Key</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[mailerlite_key]" value="<?php echo esc_attr( $opts['mailerlite_key'] ?? '' ); ?>" class="regular-text" />
                        <p class="description">Used to add applicants to your MailerLite group.</p></td>
                    </tr>
                    <tr>
                        <th>MailerLite Applicants Group ID</th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[mailerlite_group_id]" value="<?php echo esc_attr( $opts['mailerlite_group_id'] ?? '' ); ?>" class="regular-text" />
                        <p class="description">Group ID where applicants should be added.</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>JobAdder Connection</h2>
            <?php
            if ( empty( $opts['client_id'] ) || empty( $opts['client_secret'] ) ) {
                echo '<p>Please save Client ID and Client Secret above before connecting.</p>';
            } else {
                if ( isset( $_GET['code'] ) ) {
                    // Exchange code
                    $this->exchange_code_for_token( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
                    echo '<div class="updated"><p>Connected — tokens saved.</p></div>';
                }
                $tokens = get_option( $this->token_option );
             
                
                if ( empty( $tokens['access_token'] ) ) {
                    echo '<p><a class="button button-primary" href="' . esc_url( $this->get_auth_url() ) . '">Connect to JobAdder (OAuth)</a></p>';
                } else {
                    $access_token = $tokens['access_token'];
                    echo "Access Token: " . $access_token;
                    echo '<p><strong>Connected to JobAdder ✅</strong></p>';
                    echo '<p>Tokens expire at: ' . esc_html( date( 'c', $tokens['expires_at'] ?? 0 ) ) . '</p>';
                    echo '<p>Use the JobAdder webhook URL or rely on scheduled polling (every 10 minutes).</p>';
                    echo '<p><strong>Webhook URL:</strong> ' . esc_url( rest_url( 'jobadder-sync/v1/webhook' ) ) . '</p>';
                    echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=jobadder-sync&do_sync=1' ) ) . '">Run manual sync now</a></p>';
                }
            }
            ?>

            <?php
            // Manual sync trigger
            if ( isset( $_GET['do_sync'] ) && current_user_can( 'manage_options' ) ) {
                echo '<div class="updated"><p>Manual sync started — check logs.</p></div>';
                $this->fetch_and_upsert_jobs();
            }
             if ( isset( $_GET['force_cron'] ) && current_user_can( 'manage_options' ) ) {
                echo '<div class="updated"><p>Cron job triggered manually — check logs.</p></div>';
                do_action( $this->cron_hook );
            }

            // Reschedule cron
            if ( isset( $_GET['reschedule_cron'] ) && current_user_can( 'manage_options' ) ) {
                wp_clear_scheduled_hook( $this->cron_hook );
                wp_schedule_event( time(), 'ten_minutes', $this->cron_hook );
                echo '<div class="updated"><p>Cron job rescheduled.</p></div>';
                wp_redirect( admin_url( 'admin.php?page=jobadder-sync' ) );
                exit;
            }
            ?>

            <h2>RSS Feed</h2>
            <p>Use this feed in MailerLite RSS-to-email: <code><?php echo esc_url( home_url( '/feed/jobadder-jobs' ) ); ?></code></p>

        </div>
        <?php
    }

    /* ------------------ OAuth: build auth URL & exchange token ------------------ */
    private function get_auth_url() {
    $opts = get_option( $this->option_name );
    if ( empty( $opts['client_id'] ) || empty( $opts['redirect_uri'] ) ) return '#';

    $params = [
        'response_type' => 'code',
        'client_id'     => $opts['client_id'],
        'redirect_uri'  => $opts['redirect_uri'],
        // 'scope'         => 'read_job read_jobad offline_access',
       'scope' => 'read_job read_jobad read_candidate write_candidate offline_access',


    ];
 
    return 'https://id.jobadder.com/connect/authorize?' . http_build_query( $params );
}


public function exchange_code_for_token( $code ) {
    $opts = get_option( $this->option_name );

    // Log incoming code (do not log in production with real secrets)
    error_log( '[JobAdder Debug] exchange_code_for_token called. code=' . substr($code,0,8) . '...' );

    // Build request args
    $body = [
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $opts['redirect_uri'] ?? '',
        'client_id'    => $opts['client_id'] ?? '',
        'client_secret'=> $opts['client_secret'] ?? '',
    ];

    // log the request body keys (not full secret)
    $log_body = $body;
    if ( isset($log_body['client_secret']) ) {
        $log_body['client_secret'] = '***hidden***';
    }
    error_log( '[JobAdder Debug] Token request body: ' . print_r($log_body, true) );

    $resp = wp_remote_post( 'https://id.jobadder.com/connect/token', [
        'body'    => $body,
        'timeout' => 20,
    ] );

    // If WP_Error, log and return false
    if ( is_wp_error( $resp ) ) {
        error_log( '[JobAdder Error] token exchange WP_Error: ' . $resp->get_error_message() );
        return false;
    }

    // Log response code + headers + raw body (body might contain JSON error)
    $code_http = wp_remote_retrieve_response_code( $resp );
    $resp_body = wp_remote_retrieve_body( $resp );
    $resp_headers = wp_remote_retrieve_headers( $resp );

    error_log( '[JobAdder Debug] Token response HTTP code: ' . $code_http );
    // headers can be large; log only some useful headers
    error_log( '[JobAdder Debug] Token response headers: ' . print_r( array_intersect_key( (array) $resp_headers, array_flip(['content-type','date']) ), true ) );
    error_log( '[JobAdder Debug] Token response body: ' . $resp_body );

    $body_arr = json_decode( $resp_body, true );
    if ( ! empty( $body_arr['access_token'] ) ) {
        $tokens = [
            'access_token'  => $body_arr['access_token'],
            'refresh_token' => $body_arr['refresh_token'] ?? '',
            'expires_at'    => time() + ( $body_arr['expires_in'] ?? 3600 ),
        ];
    
        update_option( $this->token_option, $tokens );
        error_log( '[JobAdder Debug] Tokens saved. expires_at=' . date('c', $tokens['expires_at']) );
        return true;
    }

    // If we reached here, no access_token present
    error_log( '[JobAdder Error] Token exchange did not return access_token. Parsed body: ' . print_r( $body_arr, true ) );
    return false;
}


    /* ------------------ Token helper: get or refresh ------------------ */
    private function get_access_token() {
        $tokens = get_option( $this->token_option, [] );
        $opts = get_option( $this->option_name );

        if ( empty( $tokens['access_token'] ) ) return false;

        // If token expired (with small buffer) refresh
        if ( time() > ( (int) $tokens['expires_at'] - 60 ) ) {
            if ( empty( $tokens['refresh_token'] ) || empty( $opts['client_id'] ) || empty( $opts['client_secret'] ) ) {
                return false;
            }
            $resp = wp_remote_post( 'https://id.jobadder.com/connect/token', [
                'body' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $tokens['refresh_token'],
                    'client_id'     => $opts['client_id'],
                    'client_secret' => $opts['client_secret'],
                ],
            ] );
            if ( is_wp_error( $resp ) ) {
                error_log( 'JobAdder refresh failed: ' . $resp->get_error_message() );
                return false;
            }
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $body['access_token'] ) ) {
                $tokens = [
                    'access_token'  => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? $tokens['refresh_token'],
                    'expires_at'    => time() + ( $body['expires_in'] ?? 3600 ),
                ];
                update_option( $this->token_option, $tokens );
                return $tokens['access_token'];
            }
            return false;
        }

        return $tokens['access_token'];
    }

    /* ------------------ Register REST route for optional webhooks ------------------ */
    public function register_rest_routes() {
        register_rest_route( 'jobadder-sync/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_webhook( $request ) {
        $payload = $request->get_json_params();
        // basic log
        error_log( '[JobAdder webhook] ' . wp_json_encode( $payload ) );

        $event = $payload['event'] ?? '';
        $eventData = $payload['eventData'] ?? $payload;

        // Try known keys
        $id = $eventData['id'] ?? $eventData['adId'] ?? $eventData['jobId'] ?? $eventData['data']['id'] ?? null;
        if ( $id ) {
            // fetch single job/ad from JobAdder via API and upsert
            $this->fetch_and_upsert_jobs( $id );
            return rest_ensure_response( [ 'status' => 'ok', 'id' => $id ] );
        }

        return rest_ensure_response( [ 'status' => 'no-id' ] );
    }

    /* ------------------ Fetch & upsert jobs from JobAdder (polling) ------------------ */
  
  public function fetch_and_upsert_jobs( $single_id = null ) {
     update_option( 'jobadder_last_cron_run', current_time('mysql') );
  error_log( '[JobAdder Cron] Starting sync at ' . current_time('mysql') );    $opts = get_option( $this->option_name );
    $access = $this->get_access_token();

    if ( ! $access ) {
        error_log( 'JobAdder Sync: no access token' );
        return;
    }

    $jobboard_id = $opts['jobboard_id'] ?? '';
    $results = [];

    // Fetch a specific job if $single_id is passed
    if ( $single_id && ! empty( $jobboard_id ) ) {
        // Fetch a single ad detail using adId
        $url = "https://api.jobadder.com/v2/jobboards/{$jobboard_id}/ads/{$single_id}";
        $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $access ] ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $body ) {
                $results[] = $body; // Add the single job result to the results array
            }
        }
    } elseif ( ! empty( $jobboard_id ) ) {
        // Fetch all ads from the job board
        $url = "https://api.jobadder.com/v2/jobboards/{$jobboard_id}/ads";
        $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $access ] ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['items'] ) ) {
                $results = $body['items']; // All items (jobs)
            } else if ( is_array( $body ) ) {
                $results = $body; // fallback to array format
            }
        }
    }

    // If no results found, exit
    if ( empty( $results ) ) {
        error_log( 'JobAdder Sync: no jobs fetched.' );
        return;
    }

    // Loop through the results and fetch each job's detailed information
    foreach ( $results as $job ) {
        $adId = $job['adId'] ?? $job['id'] ?? null; // Check for adId

        // If adId exists, fetch the full job details using the adId
        if ( $adId ) {
            // Fetch the detailed job ad info using the adId
            $url = "https://api.jobadder.com/v2/jobboards/{$jobboard_id}/ads/{$adId}";
            $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $access ] ] );

            if ( ! is_wp_error( $response ) ) {
                $job_details = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( $job_details ) {
                    // Call the upsert function to save the job in WordPress
                    $this->upsert_job_from_jobadder( $job_details );
                }
            }
        } else {
            error_log( 'JobAdder Sync: Missing adId for job.' );
            
        }
    }
 error_log( '[JobAdder Cron] Sync completed, processed ' . count( $results ) . ' jobs' );
}

    /* ------------------ Create/update WP job post ------------------ */
private function upsert_job_from_jobadder( $job ) {
    $external_id = $job['adId'] ?? null;  // Use 'adId' to identify the job
    if ( empty( $external_id ) ) {
        return; // If adId is empty, return and do not process this job
    }

    // Basic fields from API
    $title = $job['title'] ?? 'Untitled Job'; // Job title
    $adId = $job['adId'] ?? ''; // Reference ID (ACF field)
    $reference = $job['reference'] ?? ''; 
    $summary = $job['summary'] ?? ''; // Summary (for excerpt)
    $description = $job['description'] ?? ''; // Description (raw HTML)

    // Check if the job post already exists based on external_id
    $existing = get_posts([
        'post_type'   => 'job',
        'meta_key'    => '_jobadder_id',
        'meta_value'  => $external_id,
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);

    // If job exists, update it
    if ( !empty($existing) ) {
        $post_id = $existing[0];
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($description), // Allow HTML content in post
            'post_excerpt' => sanitize_text_field($summary), // Excerpt for the summary
            'post_status'  => 'publish',
        ]);
    } else {
        // If job does not exist, create a new post
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($description), // Save the description as HTML
            'post_excerpt' => sanitize_text_field($summary),
            'post_type'    => 'job',
            'post_status'  => 'publish',
        ]);

        if ( $post_id ) {
            add_post_meta( $post_id, '_jobadder_id', $external_id, true ); // Save external job ID
        }
    }

    // If post creation failed, stop
    if ( !$post_id ) {
        return;
    }

    // Save ACF fields dynamically
    update_field('adId', sanitize_text_field($adId), $post_id);
    
    update_field('reference_id', sanitize_text_field($reference), $post_id);

    // Save BulletPoints repeater field (ACF)
    if ( !empty($job['bulletPoints']) && is_array($job['bulletPoints']) ) {
        $bullet_points = [];
        foreach ( $job['bulletPoints'] as $point ) {
            $bullet_points[] = ['bullet_point' => sanitize_text_field($point)];
        }
        update_field('bullet_points', $bullet_points, $post_id);
    }

    // Save Date fields (convert from ISO8601 to d/m/Y format for ACF Date Picker)
   $date_fields = ['postedAt' => 'postedat', 'updatedAt' => 'updatedat', 'expiresAt' => 'expiresat'];

    foreach ($date_fields as $api_key => $acf_field) {
        if ( !empty($job[$api_key]) ) {
            // Create the date object from the given API date string (ISO 8601 format)
            $date = date_create($job[$api_key]);

            // Check if date creation is successful
            if ( $date ) {
                // Format the date into d/m/Y format
                $formatted_date = $date->format('d/m/Y');
                update_field($acf_field, $formatted_date, $post_id); // Update the ACF field
            }
        }
    }


    // Save countryCodeHint text field (ACF)
    update_field('countrycodehint', sanitize_text_field($job['countryCodeHint'] ?? ''), $post_id);

    // Save Links repeater field with nested UI sub-repeater (ACF)
    if ( !empty($job['links']) && is_array($job['links']) ) {
        $links_data = [];
        
        // Process the UI sub-repeater
        $ui = $job['links']['ui'] ?? null;
        $ui_repeater = [];
        if ( is_array($ui) ) {
            $ui_repeater[] = [
                'self2' => sanitize_text_field($ui['self'] ?? ''),
                'applications2' => sanitize_text_field($ui['applications'] ?? ''),
            ];
        }

        // Main links repeater data
        $links_data[] = [
            'self' => sanitize_text_field($job['links']['self'] ?? ''),
            'applications' => sanitize_text_field($job['links']['applications'] ?? ''),
            'ui' => $ui_repeater,
        ];

        update_field('links', $links_data, $post_id);
    }

    // Optionally: Attach Featured Image if any
    $image_url = $job['image'] ?? $job['logoUrl'] ?? $job['companyLogo'] ?? ($job['company']['logo'] ?? '');
    if ( !empty($image_url) ) {
        $this->attach_featured_image($image_url, $post_id);
    }

    // Handle Screening Questions (Screening Questions Repeater)
 if ( !empty($job['screening']) && is_array($job['screening']) ) {
    $screening_questions = [];
    
    foreach ( $job['screening'] as $question ) {
        $question_data = [
            'question_text' => sanitize_text_field($question['question']),
            'answer_type'   => sanitize_text_field($question['answerType']),
            'value'         => []  // Initialize 'value' as an empty array
        ];

        // If answer_type is "List", we save the values as a sub-repeater (value_field)
        if ( isset($question['values']) && is_array($question['values']) ) {
            foreach ( $question['values'] as $value ) {
                $question_data['value'][] = [
                    'value_field' => sanitize_text_field($value) // Save each value in value_field
                ];
            }
        }

        // Add the question data to the screening questions array
        $screening_questions[] = $question_data;
    }

    // Save the screening questions data to ACF field (repeater field)
    update_field('screening_question', $screening_questions, $post_id);
}


    // Save Portal Salary Info
    update_field('portal_salary_rateper', sanitize_text_field($job['portal']['salary']['ratePer'] ?? ''), $post_id);
    update_field('portal_salary_ratelow', sanitize_text_field($job['portal']['salary']['rateLow'] ?? ''), $post_id);
    update_field('portal_salary_ratehigh', sanitize_text_field($job['portal']['salary']['rateHigh'] ?? ''), $post_id);

// Save Portal Fields (Category, Work Type, Location)
if ( ! empty( $job['portal']['fields'] ) && is_array( $job['portal']['fields'] ) ) {

    $assigned_terms = array(); // store term IDs for later assignment

    foreach ( $job['portal']['fields'] as $field ) {

        // ✅ Handle Parent Category
        if ( isset( $field['fieldName'] ) && $field['fieldName'] === 'Category' && ! empty( $field['value'] ) ) {
            $parent_name = sanitize_text_field( $field['value'] );

            $parent_term = term_exists( $parent_name, 'job_category' );
            if ( ! $parent_term ) {
                $parent_term = wp_insert_term( $parent_name, 'job_category' );
            }

            // Get term ID correctly (handle both array/int return)
            $parent_term_id = is_array( $parent_term ) ? $parent_term['term_id'] : $parent_term;
            $assigned_terms[] = $parent_term_id;

            // ✅ Handle Sub Category (child of parent)
            if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
                foreach ( $field['fields'] as $sub_field ) {
                    if ( isset( $sub_field['fieldName'] ) && $sub_field['fieldName'] === 'Sub Category' && ! empty( $sub_field['value'] ) ) {
                        $child_name = sanitize_text_field( $sub_field['value'] );

                        $child_term = term_exists( $child_name, 'job_category' );
                        if ( ! $child_term ) {
                            $child_term = wp_insert_term(
                                $child_name,
                                'job_category',
                                array( 'parent' => $parent_term_id )
                            );
                        }

                        $child_term_id = is_array( $child_term ) ? $child_term['term_id'] : $child_term;

                        // Only set parent if different
                        $child_data = get_term( $child_term_id, 'job_category' );
                        if ( $child_data && intval( $child_data->parent ) !== intval( $parent_term_id ) ) {
                            wp_update_term( $child_term_id, 'job_category', array( 'parent' => $parent_term_id ) );
                        }

                        $assigned_terms[] = $child_term_id;
                    }
                }
            }
        }
         if ( isset( $field['fieldName'] ) && $field['fieldName'] === 'Work Type' && ! empty( $field['value'] ) ) {
            update_field( 'work_type', sanitize_text_field( $field['value'] ), $post_id );
        }

        // ✅ Handle Location
        if ( isset( $field['fieldName'] ) && $field['fieldName'] === 'Location' && ! empty( $field['value'] ) ) {
            update_field( 'location', sanitize_text_field( $field['value'] ), $post_id );
        }
    }

    // ✅ Finally assign all terms to the post
    if ( ! empty( $assigned_terms ) ) {
        wp_set_post_terms( $post_id, $assigned_terms, 'job_category' );
    }
}


}


    /* ------------------ Attach featured image by URL ------------------ */
    private function attach_featured_image( $image_url, $post_id ) {
        if ( empty( $image_url ) ) return;
        if ( has_post_thumbnail( $post_id ) ) return;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // media_sideload_image returns HTML or WP error; get ID by sideloading properly
        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) return;

        $file = [
            'name'     => basename( $image_url ),
            'tmp_name' => $tmp
        ];
        $id = media_handle_sideload( $file, $post_id );
        if ( is_wp_error( $id ) ) {
            // cleanup
            @unlink( $tmp );
            return;
        }
        set_post_thumbnail( $post_id, $id );
    }

    /* ------------------ RSS feed (from WP posts) ------------------ */
    public function register_feed() {
        add_feed( 'jobadder-jobs', [ $this, 'generate_job_rss' ] );
    }

    public function generate_job_rss() {
        header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
        $args = [
            'post_type'      => 'job',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
        ];
        $q = new WP_Query( $args );

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        ?>
        <rss version="2.0">
            <channel>
                <title><?php echo esc_html( get_bloginfo( 'name' ) . ' — Jobs' ); ?></title>
                <link><?php echo esc_url( home_url() ); ?></link>
                <description>Latest jobs from JobAdder</description>
                <?php while ( $q->have_posts() ) : $q->the_post(); ?>
                    <item>
                        <title><![CDATA[<?php the_title(); ?>]]></title>
                        <link><![CDATA[<?php the_permalink(); ?>]]></link>
                        <pubDate><?php echo esc_html( get_the_date( 'r' ) ); ?></pubDate>
                        <description><![CDATA[<?php echo wp_kses_post( get_the_excerpt() ?: wp_trim_words( get_the_content(), 55 ) ); ?>]]></description>
                    </item>
                <?php endwhile; wp_reset_postdata(); ?>
            </channel>
        </rss>
        <?php
        exit;
    }

    /* ------------------ Append apply form to single job content ------------------ */
    public function maybe_append_apply_form( $content ) {
        if ( ! is_singular( 'job' ) || ! in_the_loop() ) {
            return $content;
        }

        // Prevent double append if form already in content
        if ( strpos( $content, 'id="jobadder-apply-form"' ) !== false ) {
            return $content;
        }

        $form = $this->get_apply_form_html( get_the_ID() );
        return $content . $form;
    }

    private function get_apply_form_html( $job_id ) {
        $nonce = wp_create_nonce( 'jobadder_apply_' . $job_id );
        $action = esc_url( admin_url( 'admin-post.php' ) );
        ob_start();
        ?>
        <div id="jobadder-apply">
            <h3>Apply for this job</h3>
            <?php if ( isset( $_GET['application_success'] ) ) : ?>
                <div class="notice notice-success">Application received. Thank you!</div>
            <?php endif; ?>
            <form id="jobadder-apply-form" action="<?php echo $action; ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="jobadder_apply" />
                <input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>" />
                <input type="hidden" name="jobadder_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

                <p><label>Name<br/><input type="text" name="applicant_name" required class="regular-text" /></label></p>
                <p><label>Email<br/><input type="email" name="applicant_email" required class="regular-text" /></label></p>
                <p><label>Phone<br/><input type="text" name="applicant_phone" class="regular-text" /></label></p>
                <p><label>Cover Letter<br/><textarea name="applicant_message" rows="6" class="large-text"></textarea></label></p>
                <p><label>Resume (pdf/doc)<br/><input type="file" name="applicant_resume" accept=".pdf,.doc,.docx" /></label></p>
                <p><label><input type="checkbox" name="subscribe_alerts" value="1" /> Subscribe to Job Alerts</label></p>

                <p><button type="submit" class="read-more-btn-form">Submit Application</button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------ Handle apply form submit ------------------ */
   public function handle_apply_form() {
    if ( empty( $_POST['job_id'] ) ) {
        wp_safe_redirect( wp_get_referer() ?: home_url() );
        exit;
    }

    $job_id = intval( $_POST['job_id'] );
    $nonce  = sanitize_text_field( $_POST['jobadder_nonce'] ?? '' );

    if ( ! wp_verify_nonce( $nonce, 'jobadder_apply_' . $job_id ) ) {
        wp_die( 'Security check failed.' );
    }

    $name      = sanitize_text_field( $_POST['applicant_name'] ?? '' );
    $email     = sanitize_email( $_POST['applicant_email'] ?? '' );
    $phone     = sanitize_text_field( $_POST['applicant_phone'] ?? '' );
    $message   = wp_kses_post( $_POST['applicant_message'] ?? '' );
    $subscribe = isset( $_POST['subscribe_alerts'] );

    $resume_url = '';
    if ( ! empty( $_FILES['applicant_resume']['tmp_name'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file   = $_FILES['applicant_resume'];
        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( isset( $upload['url'] ) ) {
            $resume_url = $upload['url'];
        }
    }

    // Create new job application post
    $app_post_id = wp_insert_post( [
        'post_type'   => 'job_application',
        'post_title'  => $name . ' - ' . get_the_title( $job_id ),
        'post_status' => 'publish',
    ] );

    if ( $app_post_id ) {

        // Save meta fields
        update_post_meta( $app_post_id, 'job_id', $job_id );
        update_field( 'name', $name, $app_post_id );
        update_field( 'email', $email, $app_post_id );
        update_field( 'phone', $phone, $app_post_id );
        update_field( 'cover_letter', $message, $app_post_id );

        // Save resume attachment to ACF 'resume' file field
        if ( ! empty( $_FILES['applicant_resume']['tmp_name'] ) ) {
            $filetype   = wp_check_filetype( $upload['file'], null );
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name( $file['name'] ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            $attach_id = wp_insert_attachment( $attachment, $upload['file'], $app_post_id );

            if ( ! is_wp_error( $attach_id ) ) {
                $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                wp_update_attachment_metadata( $attach_id, $attach_data );

                update_field( 'resume', $attach_id, $app_post_id );
            }
        }

        // MailerLite subscription
        $opts = get_option( $this->option_name );
        if ( ! empty( $opts['mailerlite_key'] ) && ! empty( $opts['mailerlite_group_id'] ) && $subscribe ) {
            $this->mailerlite_subscribe_applicant_full(
                $email,
                $name,
                $opts['mailerlite_key'],
                $opts['mailerlite_group_id'],
                $phone,
                $message,
                $resume_url,
                get_the_title($job_id) // job_apply field
            );
            $this->jobadder_create_candidate(
            $name,
            $email,
            $phone,
            $message,
            $resume_url,
            get_the_title($job_id)
            );
        }
    }

    $redirect = get_permalink( $job_id );
    $redirect = add_query_arg( 'application_success', 1, $redirect );
    wp_safe_redirect( $redirect );
    exit;
}
/**
 * Create a candidate in JobAdder when someone applies (and is added to MailerLite)
 */
private function jobadder_create_candidate($name, $email, $phone = '', $message = '', $resume_url = '', $job_apply = '') {
    $tokens = get_option($this->token_option);
    if (empty($tokens['access_token'])) {
        error_log('JobAdder: No access token found for candidate creation.');
        return false;
    }

    $access_token = $tokens['access_token'];

    // Split name into first and last name
    $name_parts = explode(' ', trim($name), 2);
    $first_name = $name_parts[0] ?? '';
    $last_name  = $name_parts[1] ?? '';

    $candidate_data = [
        'firstName' => $first_name,
        'lastName'  => $last_name,
        'email'     => $email,
        'phone'     => $phone
        
    ];

    $response = wp_remote_post('https://api.jobadder.com/v2/candidates', [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json'
        ],
        'body' => wp_json_encode($candidate_data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('JobAdder candidate creation failed: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 200 && $code < 300) {
        error_log('JobAdder candidate created successfully: ' . print_r($body, true));
        return true;
    } else {
        error_log('JobAdder candidate creation error: ' . print_r($body, true));
        return false;
    }
}


/* ------------------ MailerLite subscribe (classic API) ------------------ */
private function mailerlite_subscribe_applicant_full(
    $email,
    $name,
    $api_key,
    $group_id,
    $phone = '',
    $message = '',
    $resume_url = '',
    $job_apply = ''
) {
    if ( empty($email) || empty($api_key) || empty($group_id) ) return false;

    $body = [
        'email' => $email,
        'name'  => $name,
        'fields' => [
            'phone_number' => $phone,
            'cover_letter' => $message,
            'resume_url'   => $resume_url,
            'job_apply'    => $job_apply,
        ],
    ];

    $url = "https://api.mailerlite.com/api/v2/groups/{$group_id}/subscribers";

    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-MailerLite-ApiKey' => $api_key,
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 20,
    ];

    $resp = wp_remote_post($url, $args);

    if ( is_wp_error($resp) ) {
        error_log('MailerLite subscribe error: ' . $resp->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body_resp = wp_remote_retrieve_body($resp);

    error_log('MailerLite Response Code: ' . $code);
    error_log('MailerLite Response Body: ' . $body_resp);

    return ($code >= 200 && $code < 300);
}
  /* ------------------ Debug cron status ------------------ */
    public function debug_cron_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $next_scheduled = wp_next_scheduled( $this->cron_hook );
        $cron_enabled = $next_scheduled !== false;

        echo '<h2>Cron Debug Info</h2>';
        echo '<p><strong>Status:</strong> ' . ($cron_enabled ? 'Enabled' : 'Disabled') . '</p>';
        echo '<p><strong>Next Run:</strong> ' . ($cron_enabled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled') . '</p>';
        echo '<p><strong>Last Run:</strong> ' . get_option('jobadder_last_cron_run', 'Never') . '</p>';
        
        wp_die();
    }


}

// Handle JobAdder OAuth callback manually (for /jobadder-callback route)
add_action( 'init', function() {
    if ( isset($_GET['jobadder_callback']) && isset($_GET['code']) ) {
        error_log('[JobAdder Debug] Custom callback triggered.');
        $plugin = new JobAdder_Sync_Apply();
        $plugin->exchange_code_for_token( sanitize_text_field($_GET['code']) );
        echo '<h2>✅ Connected to JobAdder successfully. Tokens saved.</h2>';
        exit;
    }
});




// Bootstrap
new JobAdder_Sync_Apply();




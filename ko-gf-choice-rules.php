<?php
/**
 * Plugin Name: KO – GF Choice Rules
 * Description: Hide/disable specific Gravity Forms choices based on other fields. Simple rules via admin UI + advanced multi-condition rules (value-based, ignoring “|price” suffix).
 * Version:     2.9.0
 * Author:      KO
 * Text Domain: ko-gf-choice-rules
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KO_GF_Choice_Rules {

    const OPT_SIMPLE = 'ko_gf_lock_rules';
    const OPT_ADV    = 'ko_gf_advanced_rules_rows';

    public function __construct() {
        add_action( 'admin_menu',         [ $this, 'add_menu' ] );
        add_action( 'admin_init',         [ $this, 'register_settings' ] );
        add_action( 'admin_init',         [ $this, 'handle_export' ] );
        add_action( 'admin_post_ko_gf_import_rules', [ $this, 'handle_import' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_filter( 'gform_validation',   [ $this, 'validate_submission' ], 10, 2 );
    }

    /* ---------------- Admin (Simple + Advanced) ---------------- */

    public function add_menu() {
        add_options_page(
            'KO GF Choice Rules',
            'KO GF Choice Rules',
            'manage_options',
            'ko-gf-choice-rules',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting(
            'ko_gf_choice_rules_group',
            self::OPT_SIMPLE,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_simple_rules' ],
                'default'           => [],
            ]
        );

        register_setting(
            'ko_gf_choice_rules_group',
            self::OPT_ADV,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_advanced_rows' ],
                'default'           => [],
            ]
        );
    }

    public function sanitize_simple_rules( $rules ) {
        if ( ! is_array( $rules ) ) {
            return [];
        }
        $clean = [];
        foreach ( $rules as $r ) {
            $clean[] = [
                'form_id'       => (int) ( $r['form_id']       ?? 0 ),
                'trigger_field' => (int) ( $r['trigger_field'] ?? 0 ),
                'trigger_value' => sanitize_text_field( $r['trigger_value'] ?? '' ),
                'target_field'  => (int) ( $r['target_field']  ?? 0 ),
                'target_value'  => sanitize_text_field( $r['target_value']  ?? '' ),
                'action'        => in_array( $r['action'] ?? '', [ 'hide', 'disable' ], true ) ? $r['action'] : 'hide',
                'logic_mode'    => in_array( $r['logic_mode'] ?? '', [ 'when_trigger_not_match', 'when_trigger_match' ], true )
                                   ? $r['logic_mode'] : 'when_trigger_not_match',
            ];
        }
        return $clean;
    }

    public function sanitize_advanced_rows( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $clean = [];
        foreach ( $rows as $r ) {
            // Skip completely empty rows
            $has_any = false;
            foreach ( $r as $v ) {
                if ( (string) $v !== '' ) { $has_any = true; break; }
            }
            if ( ! $has_any ) {
                continue;
            }

            $key = $r['rule_key'] ?? '';
            $key = $key !== '' ? sanitize_key( $key ) : '';

            $cond_type = $r['cond_type'] ?? 'numeric';
            $cond_type = in_array( $cond_type, [ 'numeric', 'string' ], true ) ? $cond_type : 'numeric';

            $cond_operator = $r['cond_operator'] ?? '';
            $allowed_ops   = [ '<', '<=', '>', '>=', '=', '!=', 'contains' ];
            if ( ! in_array( $cond_operator, $allowed_ops, true ) ) {
                $cond_operator = '=';
            }

            $unit_mode = $r['unit_mode'] ?? '';
            $unit_mode = in_array( $unit_mode, [ '', 'miles_km' ], true ) ? $unit_mode : '';

            $clean[] = [
                'rule_key'      => $key,
                'form_id'       => (int) ( $r['form_id']       ?? 0 ),
                'target_field'  => (int) ( $r['target_field']  ?? 0 ),
                'target_value'  => sanitize_text_field( $r['target_value'] ?? '' ),
                'action'        => in_array( $r['action'] ?? '', [ 'hide', 'disable' ], true ) ? $r['action'] : 'hide',

                'cond_field'    => (int) ( $r['cond_field']    ?? 0 ),
                'cond_type'     => $cond_type,
                'cond_operator' => $cond_operator,
                'cond_value'    => sanitize_text_field( $r['cond_value'] ?? '' ),
                'unit_mode'     => $unit_mode,
                'unit_field'    => (int) ( $r['unit_field']    ?? 0 ),
            ];
        }
        return $clean;
    }

    /* ---------------- Import / Export ---------------- */

    public function handle_export() {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ko-gf-choice-rules' ) {
            return;
        }

        if ( ! isset( $_GET['ko_gf_export_rules'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'ko_gf_export_rules' );

        $data = [
            'plugin'         => 'ko-gf-choice-rules',
            'version'        => '2.9.0',
            'exported_at'    => current_time( 'mysql' ),
            'simple_rules'   => get_option( self::OPT_SIMPLE, [] ),
            'advanced_rules' => get_option( self::OPT_ADV, [] ),
        ];

        $filename = 'ko-gf-choice-rules-' . date( 'Ymd-His' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import rules.', 'ko-gf-choice-rules' ) );
        }

        check_admin_referer( 'ko_gf_import_rules' );

        if ( empty( $_FILES['ko_gf_import_file']['tmp_name'] ) ) {
            $redirect = add_query_arg(
                [
                    'page'          => 'ko-gf-choice-rules',
                    'import_status' => 'error',
                    'import_msg'    => rawurlencode( 'No file uploaded.' ),
                ],
                admin_url( 'options-general.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $contents = file_get_contents( $_FILES['ko_gf_import_file']['tmp_name'] );
        if ( $contents === false ) {
            $redirect = add_query_arg(
                [
                    'page'          => 'ko-gf-choice-rules',
                    'import_status' => 'error',
                    'import_msg'    => rawurlencode( 'Unable to read uploaded file.' ),
                ],
                admin_url( 'options-general.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $data = json_decode( $contents, true );
        if ( ! is_array( $data ) ) {
            $redirect = add_query_arg(
                [
                    'page'          => 'ko-gf-choice-rules',
                    'import_status' => 'error',
                    'import_msg'    => rawurlencode( 'Invalid JSON structure.' ),
                ],
                admin_url( 'options-general.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        $simple   = isset( $data['simple_rules'] )   && is_array( $data['simple_rules'] )   ? $data['simple_rules']   : [];
        $advanced = isset( $data['advanced_rules'] ) && is_array( $data['advanced_rules'] ) ? $data['advanced_rules'] : [];

        $simple_clean   = $this->sanitize_simple_rules( $simple );
        $advanced_clean = $this->sanitize_advanced_rows( $advanced );

        update_option( self::OPT_SIMPLE, $simple_clean );
        update_option( self::OPT_ADV,    $advanced_clean );

        $redirect = add_query_arg(
            [
                'page'          => 'ko-gf-choice-rules',
                'import_status' => 'success',
            ],
            admin_url( 'options-general.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $simple   = get_option( self::OPT_SIMPLE, [] );
        $adv_rows = get_option( self::OPT_ADV, [] );

        // Seed defaults if empty
        if ( empty( $simple ) ) {
            $simple = [
                [
                    'form_id'       => 2,
                    'trigger_field' => 15,
                    'trigger_value' => 'Warranty & Truck',
                    'target_field'  => 63,
                    'target_value'  => 'VOLVO CERTIFIED AND EATS 3 MO/25K',
                    'action'        => 'hide',
                    'logic_mode'    => 'when_trigger_not_match',
                ],
            ];
        }

        if ( empty( $adv_rows ) ) {
            $adv_rows = [
                [
                    'rule_key'      => 'vocational_24',
                    'form_id'       => 2,
                    'target_field'  => 63,
                    'target_value'  => 'VOLVO CERTIFIED AND EATS VOCATIONAL 24 MO/250K',
                    'action'        => 'hide',
                    'cond_field'    => 9,
                    'cond_type'     => 'numeric',
                    'cond_operator' => '<',
                    'cond_value'    => '250000',
                    'unit_mode'     => 'miles_km',
                    'unit_field'    => 59,
                ],
                [
                    'rule_key'      => 'vocational_24',
                    'form_id'       => 2,
                    'target_field'  => 63,
                    'target_value'  => 'VOLVO CERTIFIED AND EATS VOCATIONAL 24 MO/250K',
                    'action'        => 'hide',
                    'cond_field'    => 47,
                    'cond_type'     => 'string',
                    'cond_operator' => '=',
                    'cond_value'    => 'VHD',
                    'unit_mode'     => '',
                    'unit_field'    => 0,
                ],
            ];
        }

        // Export URL
        $export_url = wp_nonce_url(
            admin_url( 'options-general.php?page=ko-gf-choice-rules&ko_gf_export_rules=1' ),
            'ko_gf_export_rules'
        );

        // Import status message
        $import_status = isset( $_GET['import_status'] ) ? sanitize_text_field( wp_unslash( $_GET['import_status'] ) ) : '';
        $import_msg    = isset( $_GET['import_msg'] ) ? urldecode( sanitize_text_field( wp_unslash( $_GET['import_msg'] ) ) ) : '';

        ?>
        <div class="wrap">
            <h1>KO GF Choice Rules</h1>

            <?php if ( $import_status === 'success' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Rules imported successfully.', 'ko-gf-choice-rules' ); ?></p>
                </div>
            <?php elseif ( $import_status === 'error' && $import_msg ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $import_msg ); ?></p>
                </div>
            <?php endif; ?>

            <p style="margin-bottom: 12px;">
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'Export Rules (JSON)', 'ko-gf-choice-rules' ); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:20px;">
                <?php wp_nonce_field( 'ko_gf_import_rules' ); ?>
                <input type="hidden" name="action" value="ko_gf_import_rules" />
                <label for="ko-gf-import-file">
                    <strong><?php esc_html_e( 'Import Rules (JSON)', 'ko-gf-choice-rules' ); ?></strong>
                </label>
                <input type="file" id="ko-gf-import-file" name="ko_gf_import_file" accept=".json" />
                <?php submit_button( __( 'Import', 'ko-gf-choice-rules' ), 'secondary', 'submit', false ); ?>
                <p class="description">
                    <?php esc_html_e( 'Imports Simple and Advanced rules from a JSON export. Existing rules will be overwritten.', 'ko-gf-choice-rules' ); ?>
                </p>
            </form>

            <h2>Simple Rules</h2>
            <p><strong>Use Simple Rules</strong> to hide/disable choices based on a single trigger field + value.  <em>More info available in the README.txt.</em></p>
            <p style="margin: 8px 0; padding: 10px; background:#f0f6ff; border-left:4px solid #2271b1;"><strong>Note:</strong> Target Value is the radio input’s <code>value</code> (base part). The plugin automatically ignores anything after a pipe (<code>|</code>), e.g. <code>|1820</code>.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'ko_gf_choice_rules_group' ); ?>

                <table class="widefat striped" id="ko-gf-rules-table">
                    <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Trigger Field ID</th>
                        <th>Trigger Value</th>
                        <th>Target Field ID</th>
                        <th>Target Value Equals (base)</th>
                        <th>Action</th>
                        <th>Logic Mode</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $simple as $i => $r ) : ?>
                        <tr>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][form_id]"; ?>" value="<?php echo esc_attr( $r['form_id'] ); ?>" min="1" style="width:80px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][trigger_field]"; ?>" value="<?php echo esc_attr( $r['trigger_field'] ); ?>" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][trigger_value]"; ?>" value="<?php echo esc_attr( $r['trigger_value'] ); ?>" style="width:220px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][target_field]"; ?>" value="<?php echo esc_attr( $r['target_field'] ); ?>" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][target_value]"; ?>" value="<?php echo esc_attr( $r['target_value'] ); ?>" style="width:260px" placeholder="Base value (no |price)"></td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][action]"; ?>">
                                    <option value="hide"    <?php selected( $r['action'], 'hide' ); ?>>Hide</option>
                                    <option value="disable" <?php selected( $r['action'], 'disable' ); ?>>Disable</option>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_SIMPLE ) . "[$i][logic_mode]"; ?>">
                                    <option value="when_trigger_not_match" <?php selected( $r['logic_mode'], 'when_trigger_not_match' ); ?>>
                                        When trigger does NOT equal Trigger Value
                                    </option>
                                    <option value="when_trigger_match" <?php selected( $r['logic_mode'], 'when_trigger_match' ); ?>>
                                        When trigger equals Trigger Value
                                    </option>
                                </select>
                            </td>
                            <td><a href="#" class="button ko-remove-row">Remove</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p><a href="#" id="ko-add-row" class="button">Add Simple Rule</a></p>

                <hr />

                <h2>Advanced Rules</h2>
				<p>
                    Use <strong>Advanced Rules</strong> to build multi-condition logic. All rows with the same
                    <strong>Rule Key</strong> are combined (AND) into one rule. If any condition fails, the
                    rule’s action is applied (e.g. <em>Hide</em>). <em>More info available in the README.txt.</em>
                </p>
                <p style="margin: 8px 0; padding: 10px; background:#f0f6ff; border-left:4px solid #2271b1;">
                    <strong>Note:</strong> Odometer values should be entered in <strong>Miles</strong>.
                    If the user selects <strong>Kilometers</strong> in the unit field, the plugin will
                    automatically convert the value to miles (KM × 0.621371) before applying numeric rules.
                </p>
                <table class="widefat striped" id="ko-gf-adv-table">
                    <thead>
                    <tr>
                        <th>Rule Key</th>
                        <th>Form ID</th>
                        <th>Target Field ID</th>
                        <th>Target Value Equals (base)</th>
                        <th>Action</th>
                        <th>Trigger Field ID</th>
                        <th>Trigger Type</th>
                        <th>Trigger Operator</th>
                        <th>Trigger Value</th>
                        <th>Unit Mode</th>
                        <th>Unit Field ID</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $adv_rows as $i => $r ) : ?>
                        <tr>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][rule_key]"; ?>" value="<?php echo esc_attr( $r['rule_key'] ); ?>" style="width:110px" placeholder="e.g. vocational_24"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][form_id]"; ?>" value="<?php echo esc_attr( $r['form_id'] ); ?>" min="1" style="width:70px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][target_field]"; ?>" value="<?php echo esc_attr( $r['target_field'] ); ?>" min="1" style="width:90px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][target_value]"; ?>" value="<?php echo esc_attr( $r['target_value'] ); ?>" style="width:260px" placeholder="Base value (no |price)"></td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][action]"; ?>">
                                    <option value="hide"    <?php selected( $r['action'], 'hide' ); ?>>Hide</option>
                                    <option value="disable" <?php selected( $r['action'], 'disable' ); ?>>Disable</option>
                                </select>
                            </td>

                            <td><input type="number" name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][cond_field]"; ?>" value="<?php echo esc_attr( $r['cond_field'] ); ?>" min="1" style="width:90px" placeholder="Trigger Field ID"></td>

                            <td>
                                <select name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][cond_type]"; ?>">
                                    <option value="numeric" <?php selected( $r['cond_type'], 'numeric' ); ?>>numeric</option>
                                    <option value="string"  <?php selected( $r['cond_type'], 'string' ); ?>>string</option>
                                </select>
                            </td>

                            <td>
                                <select name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][cond_operator]"; ?>">
                                    <option value="<"  <?php selected( $r['cond_operator'], '<' ); ?>>Less than (&lt;)</option>
                                    <option value="<=" <?php selected( $r['cond_operator'], '<=' ); ?>>Less than or equal (&lt;=)</option>
                                    <option value=">"  <?php selected( $r['cond_operator'], '>' ); ?>>Greater than (&gt;)</option>
                                    <option value=">=" <?php selected( $r['cond_operator'], '>=' ); ?>>Greater than or equal (&gt;=)</option>
                                    <option value="="  <?php selected( $r['cond_operator'], '=' ); ?>>Equals (=)</option>
                                    <option value="!=" <?php selected( $r['cond_operator'], '!=' ); ?>>Does not equal (!=)</option>
                                    <option value="contains" <?php selected( $r['cond_operator'], 'contains' ); ?>>Contains</option>
                                </select>
                            </td>

                            <td><input type="text" name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][cond_value]"; ?>" value="<?php echo esc_attr( $r['cond_value'] ); ?>" style="width:140px" placeholder="Trigger Value"></td>

                            <td>
                                <select name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][unit_mode]"; ?>">
                                    <option value=""          <?php selected( $r['unit_mode'], '' ); ?>>—</option>
                                    <option value="miles_km"  <?php selected( $r['unit_mode'], 'miles_km' ); ?>>Miles / Kilometers</option>
                                </select>
                            </td>

                            <td><input type="number" name="<?php echo esc_attr( self::OPT_ADV ) . "[$i][unit_field]"; ?>" value="<?php echo esc_attr( $r['unit_field'] ); ?>" min="0" style="width:90px" placeholder="Unit Field ID"></td>

                            <td><a href="#" class="button ko-adv-remove-row">Remove</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p><a href="#" id="ko-add-adv-row" class="button">Add Advanced Row</a></p>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        (function(){
          // Simple rules
          const simpleTable = document.querySelector('#ko-gf-rules-table tbody');
          const simpleAdd   = document.getElementById('ko-add-row');
          if(simpleTable && simpleAdd){
            simpleAdd.addEventListener('click', function(e){
              e.preventDefault();
              const idx = simpleTable.querySelectorAll('tr').length;
              const k   = '<?php echo esc_js( self::OPT_SIMPLE ); ?>';
              const row = `
              <tr>
                <td><input type="number" name="${k}[${idx}][form_id]" value="2" min="1" style="width:80px"></td>
                <td><input type="number" name="${k}[${idx}][trigger_field]" value="15" min="1" style="width:110px"></td>
                <td><input type="text"   name="${k}[${idx}][trigger_value]" value="Warranty & Truck" style="width:220px"></td>
                <td><input type="number" name="${k}[${idx}][target_field]" value="63" min="1" style="width:110px"></td>
                <td><input type="text"   name="${k}[${idx}][target_value]" value="" style="width:260px" placeholder="Base value (no |price)"></td>
                <td>
                  <select name="${k}[${idx}][action]">
                    <option value="hide" selected>Hide</option>
                    <option value="disable">Disable</option>
                  </select>
                </td>
                <td>
                  <select name="${k}[${idx}][logic_mode]">
                    <option value="when_trigger_not_match" selected>When trigger does NOT equal Trigger Value</option>
                    <option value="when_trigger_match">When trigger equals Trigger Value</option>
                  </select>
                </td>
                <td><a href="#" class="button ko-remove-row">Remove</a></td>
              </tr>`;
              simpleTable.insertAdjacentHTML('beforeend', row);
            });

            simpleTable.addEventListener('click', function(e){
              if(e.target && e.target.classList.contains('ko-remove-row')){
                e.preventDefault();
                e.target.closest('tr').remove();
              }
            });
          }

          // Advanced rows
          const advTable = document.querySelector('#ko-gf-adv-table tbody');
          const advAdd   = document.getElementById('ko-add-adv-row');
          if(advTable && advAdd){
            advAdd.addEventListener('click', function(e){
              e.preventDefault();
              const idx = advTable.querySelectorAll('tr').length;
              const k   = '<?php echo esc_js( self::OPT_ADV ); ?>';
              const row = `
              <tr>
                <td><input type="text"   name="${k}[${idx}][rule_key]" value="" style="width:110px" placeholder="e.g. vocational_24"></td>
                <td><input type="number" name="${k}[${idx}][form_id]" value="2" min="1" style="width:70px"></td>
                <td><input type="number" name="${k}[${idx}][target_field]" value="63" min="1" style="width:90px"></td>
                <td><input type="text"   name="${k}[${idx}][target_value]" value="" style="width:260px" placeholder="Base value (no |price)"></td>
                <td>
                  <select name="${k}[${idx}][action]">
                    <option value="hide" selected>Hide</option>
                    <option value="disable">Disable</option>
                  </select>
                </td>
                <td><input type="number" name="${k}[${idx}][cond_field]" value="" min="1" style="width:90px" placeholder="Trigger Field ID"></td>
                <td>
                  <select name="${k}[${idx}][cond_type]">
                    <option value="numeric" selected>numeric</option>
                    <option value="string">string</option>
                  </select>
                </td>
                <td>
                  <select name="${k}[${idx}][cond_operator]">
                    <option value="<">Less than (&lt;)</option>
                    <option value="<=">Less than or equal (&lt;=)</option>
                    <option value=">">Greater than (&gt;)</option>
                    <option value=">=">Greater than or equal (&gt;=)</option>
                    <option value="=" selected>Equals (=)</option>
                    <option value="!=">Does not equal (!=)</option>
                    <option value="contains">Contains</option>
                  </select>
                </td>
                <td><input type="text" name="${k}[${idx}][cond_value]" value="" style="width:140px" placeholder="Trigger Value"></td>
                <td>
                  <select name="${k}[${idx}][unit_mode]">
                    <option value="" selected>—</option>
                    <option value="miles_km">Miles / Kilometers</option>
                  </select>
                </td>
                <td><input type="number" name="${k}[${idx}][unit_field]" value="" min="0" style="width:90px" placeholder="Unit Field ID"></td>
                <td><a href="#" class="button ko-adv-remove-row">Remove</a></td>
              </tr>`;
              advTable.insertAdjacentHTML('beforeend', row);
            });

            advTable.addEventListener('click', function(e){
              if(e.target && e.target.classList.contains('ko-adv-remove-row')){
                e.preventDefault();
                e.target.closest('tr').remove();
              }
            });
          }
        })();
        </script>
        <?php
    }

    /* ---------------- Build advanced rules struct for JS ---------------- */

    private function get_advanced_rules() {
        $rows = get_option( self::OPT_ADV, [] );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return [];
        }

        $rules = [];

        foreach ( $rows as $row ) {
            $key = $row['rule_key'] ?? '';
            if ( $key === '' ) {
                // Fallback: unique key per row if not specified
                $key = 'rule_' . md5( serialize( $row ) );
            }

            if ( ! isset( $rules[ $key ] ) ) {
                $rules[ $key ] = [
                    'form_id'      => (int) ( $row['form_id']      ?? 0 ),
                    'target_field' => (int) ( $row['target_field'] ?? 0 ),
                    'target_value' => (string) ( $row['target_value'] ?? '' ),
                    'action'       => in_array( $row['action'] ?? '', [ 'hide', 'disable' ], true )
                                      ? $row['action'] : 'hide',
                    'conditions'   => [],
                ];
            }

            // Condition
            if ( ! empty( $row['cond_field'] ) && (string) ( $row['cond_value'] ?? '' ) !== '' ) {
                $rules[ $key ]['conditions'][] = [
                    'field_id'   => (int) ( $row['cond_field'] ?? 0 ),
                    'type'       => in_array( $row['cond_type'] ?? 'numeric', [ 'numeric', 'string' ], true )
                                    ? $row['cond_type'] : 'numeric',
                    'operator'   => $row['cond_operator'] ?? '=',
                    'value'      => (string) ( $row['cond_value'] ?? '' ),
                    'unit_mode'  => in_array( $row['unit_mode'] ?? '', [ '', 'miles_km' ], true )
                                    ? $row['unit_mode'] : '',
                    'unit_field' => (int) ( $row['unit_field'] ?? 0 ),
                ];
            }
        }

        return array_values( $rules );
    }

    /* ---------------- Frontend ---------------- */

    public function enqueue_frontend() {
        wp_enqueue_script(
            'ko-gf-lock-frontend',
            plugins_url( 'ko-gf-lock-frontend.js', __FILE__ ),
            [],
            '2.9.0',
            true
        );

        $simple   = get_option( self::OPT_SIMPLE, [] );
        $advanced = $this->get_advanced_rules();

        $simple_attr   = esc_attr( wp_json_encode( $simple,   JSON_UNESCAPED_SLASHES ) );
        $advanced_attr = esc_attr( wp_json_encode( $advanced, JSON_UNESCAPED_SLASHES ) );

        add_filter( 'script_loader_tag', function( $tag, $handle, $src ) use ( $simple_attr, $advanced_attr ) {
            if ( $handle === 'ko-gf-lock-frontend' ) {
                $attr = ' data-ko-rules=\'' . $simple_attr . '\' data-ko-adv-rules=\'' . $advanced_attr . '\'';
                $tag  = str_replace( ' src=', $attr . ' src=', $tag );
            }
            return $tag;
        }, 10, 3 );
    }

    /* ---------------- Validation (simple rules only) ---------------- */

    public function validate_submission( $result, $form ) {
        $rules = get_option( self::OPT_SIMPLE, [] );
        if ( empty( $rules ) ) {
            return $result;
        }

        $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

        foreach ( $rules as $r ) {
            if ( (int) ( $r['form_id'] ?? 0 ) !== $form_id ) continue;

            $trigger_field = (int) ( $r['trigger_field'] ?? 0 );
            $target_field  = (int) ( $r['target_field']  ?? 0 );
            $trigger_val   = (string) ( $r['trigger_value'] ?? '' );
            $target_value  = (string) ( $r['target_value']  ?? '' );

            $trigger_input = rgpost( 'input_' . $trigger_field );
            $target_input  = rgpost( 'input_' . $target_field );

            $value_matches   = ( $target_value !== '' && $this->ko_base_val( $target_input ) === $this->ko_base_val( $target_value ) );
            $trigger_matches = ( $this->ko_norm( $trigger_input ) === $this->ko_norm( $trigger_val ) );
            $should_act      = ( ( $r['logic_mode'] ?? 'when_trigger_not_match' ) === 'when_trigger_match' )
                               ? $trigger_matches : ! $trigger_matches;

            if ( $should_act && $value_matches ) {
                foreach ( $form['fields'] as &$field ) {
                    if ( (int) $field->id === $target_field ) {
                        $field->failed_validation  = true;
                        $field->validation_message = __( 'This option is not available with your selection.', 'ko-gf-choice-rules' );
                        break;
                    }
                }
                $result['is_valid'] = false;
                $result['form']     = $form;
                return $result;
            }
        }

        return $result;
    }

    /* ---------------- Helpers ---------------- */

    private function ko_norm( $s ) {
        $s = (string) $s;
        $s = preg_replace( '/\x{00A0}/u', ' ', $s );
        $s = preg_replace( '/\s+/u', ' ', $s );
        return strtolower( trim( $s ) );
    }

    private function ko_base_val( $s ) {
        $parts = explode( '|', (string) $s, 2 );
        return trim( $parts[0] );
    }
}

new KO_GF_Choice_Rules();
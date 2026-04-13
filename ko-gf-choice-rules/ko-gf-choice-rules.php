<?php
/**
 * Plugin Name: KO – GF Choice Rules
 * Description: Admin UI to hide/disable specific Gravity Forms choices based on another field’s value. Matches by CHOICE VALUE (exact), ignoring any “|price” suffix. Embeds rules in the frontend script tag (no JSON/REST). Includes server-side validation.
 * Version:     2.5.1
 * Author:      KO
 * Text Domain: ko-gf-choice-rules
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KO_GF_Choice_Rules {
    const OPT_KEY = 'ko_gf_lock_rules';

    public function __construct() {
        add_action( 'admin_menu',          [ $this, 'add_menu' ] );
        add_action( 'admin_init',          [ $this, 'register_settings' ] );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_frontend' ] );
        add_filter( 'gform_validation',    [ $this, 'validate_submission' ], 10, 2 );
    }

    /* ---------------- Admin ---------------- */

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
            self::OPT_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_rules' ],
                'default'           => [],
            ]
        );
    }

    public function sanitize_rules( $rules ) {
        if ( ! is_array( $rules ) ) return [];
        $clean = [];
        foreach ( $rules as $r ) {
            $clean[] = [
                'form_id'       => isset( $r['form_id'] ) ? (int) $r['form_id'] : 0,
                'trigger_field' => isset( $r['trigger_field'] ) ? (int) $r['trigger_field'] : 0,
                'trigger_value' => isset( $r['trigger_value'] ) ? sanitize_text_field( $r['trigger_value'] ) : '',
                'target_field'  => isset( $r['target_field'] ) ? (int) $r['target_field'] : 0,
                'target_value'  => isset( $r['target_value'] ) ? sanitize_text_field( $r['target_value'] ) : '',
                'action'        => ( isset( $r['action'] ) && in_array( $r['action'], [ 'hide', 'disable' ], true ) ) ? $r['action'] : 'hide',
                'logic_mode'    => ( isset( $r['logic_mode'] ) && in_array( $r['logic_mode'], [ 'when_trigger_not_match', 'when_trigger_match' ], true ) ) ? $r['logic_mode'] : 'when_trigger_not_match',
            ];
        }
        return $clean;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $rules = get_option( self::OPT_KEY, [] );
        ?>
        <div class="wrap">
            <h1>KO GF Choice Rules</h1>
            <p><strong>Tip:</strong> Use <em>Target Value Equals</em> (exact). It’s the radio input’s <code>value</code>. This plugin automatically ignores any suffix after a pipe (e.g. <code>|1820</code>).</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'ko_gf_choice_rules_group' ); ?>
                <table class="widefat striped" id="ko-gf-rules-table">
                    <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Trigger Field ID</th>
                        <th>Trigger Value</th>
                        <th>Target Field ID</th>
                        <th>Target Value Equals</th>
                        <th>Action</th>
                        <th>Logic Mode</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rules ) ) : ?>
                        <tr>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][form_id]" value="2" min="1" style="width:90px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][trigger_field]" value="15" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][trigger_value]" value="Purchasing Warranty & Truck" style="width:240px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][target_field]" value="63" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][target_value]" value="" placeholder="e.g. VOLVO CERTIFIED AND EATS 3 MO/25K" style="width:320px"></td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][action]">
                                    <option value="hide" selected>Hide</option>
                                    <option value="disable">Disable</option>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_KEY ); ?>[0][logic_mode]">
                                    <option value="when_trigger_not_match" selected>When trigger ≠ value</option>
                                    <option value="when_trigger_match">When trigger = value</option>
                                </select>
                            </td>
                            <td></td>
                        </tr>
                    <?php else : foreach ( $rules as $i => $r ) : ?>
                        <tr>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][form_id]"; ?>" value="<?php echo esc_attr( $r['form_id'] ); ?>" min="1" style="width:90px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][trigger_field]"; ?>" value="<?php echo esc_attr( $r['trigger_field'] ); ?>" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][trigger_value]"; ?>" value="<?php echo esc_attr( $r['trigger_value'] ); ?>" style="width:240px"></td>
                            <td><input type="number" name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][target_field]"; ?>" value="<?php echo esc_attr( $r['target_field'] ); ?>" min="1" style="width:110px"></td>
                            <td><input type="text"   name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][target_value]"; ?>" value="<?php echo esc_attr( $r['target_value'] ); ?>" placeholder="exact input value (no price)" style="width:320px"></td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][action]"; ?>">
                                    <option value="hide" <?php selected( $r['action'], 'hide' ); ?>>Hide</option>
                                    <option value="disable" <?php selected( $r['action'], 'disable' ); ?>>Disable</option>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( self::OPT_KEY ) . "[$i][logic_mode]"; ?>">
                                    <option value="when_trigger_not_match" <?php selected( $r['logic_mode'], 'when_trigger_not_match' ); ?>>When trigger ≠ value</option>
                                    <option value="when_trigger_match" <?php selected( $r['logic_mode'], 'when_trigger_match' ); ?>>When trigger = value</option>
                                </select>
                            </td>
                            <td><a href="#" class="button ko-remove-row">Remove</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <p><a href="#" id="ko-add-row" class="button">Add Rule</a></p>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function(){
          const table=document.querySelector('#ko-gf-rules-table tbody');
          document.getElementById('ko-add-row').addEventListener('click',function(e){
            e.preventDefault();
            const idx=table.querySelectorAll('tr').length;
            const k='<?php echo esc_js( self::OPT_KEY ); ?>';
            const row = `
            <tr>
              <td><input type="number" name="${k}[${idx}][form_id]" value="2" min="1" style="width:90px"></td>
              <td><input type="number" name="${k}[${idx}][trigger_field]" value="15" min="1" style="width:110px"></td>
              <td><input type="text"   name="${k}[${idx}][trigger_value]" value="Purchasing Warranty & Truck" style="width:240px"></td>
              <td><input type="number" name="${k}[${idx}][target_field]" value="63" min="1" style="width:110px"></td>
              <td><input type="text"   name="${k}[${idx}][target_value]" value="" placeholder="e.g. VOLVO CERTIFIED AND EATS 3 MO/25K" style="width:320px"></td>
              <td>
                <select name="${k}[${idx}][action]">
                  <option value="hide" selected>Hide</option>
                  <option value="disable">Disable</option>
                </select>
              </td>
              <td>
                <select name="${k}[${idx}][logic_mode]">
                  <option value="when_trigger_not_match" selected>When trigger ≠ value</option>
                  <option value="when_trigger_match">When trigger = value</option>
                </select>
              </td>
              <td><a href="#" class="button ko-remove-row">Remove</a></td>
            </tr>`;
            table.insertAdjacentHTML('beforeend', row);
          });
          table.addEventListener('click', function(e){
            if (e.target && e.target.classList.contains('ko-remove-row')) {
              e.preventDefault();
              e.target.closest('tr').remove();
            }
          });
        })();
        </script>
        <?php
    }

    /* ---------------- Frontend ---------------- */

    public function enqueue_frontend() {
        wp_enqueue_script(
            'ko-gf-lock-frontend',
            plugins_url( 'ko-gf-lock-frontend.js', __FILE__ ),
            [],
            '2.5.1',
            true
        );

        $rules      = get_option( self::OPT_KEY, [] );
        $rules_attr = esc_attr( wp_json_encode( $rules, JSON_UNESCAPED_SLASHES ) );

        add_filter( 'script_loader_tag', function( $tag, $handle, $src ) use ( $rules_attr ) {
            if ( $handle === 'ko-gf-lock-frontend' ) {
                $attr = ' data-ko-rules=\'' . $rules_attr . '\'';
                $tag  = str_replace( ' src=', $attr . ' src=', $tag );
            }
            return $tag;
        }, 10, 3 );
    }

    /* ---------------- Validation ---------------- */

    public function validate_submission( $result, $form ) {
        $rules = get_option( self::OPT_KEY, [] );
        if ( empty( $rules ) ) return $result;

        $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

        foreach ( $rules as $r ) {
            if ( (int) ( $r['form_id'] ?? 0 ) !== $form_id ) continue;

            $trigger_field = (int) ( $r['trigger_field'] ?? 0 );
            $target_field  = (int) ( $r['target_field']  ?? 0 );
            $trigger_val   = (string) ( $r['trigger_value'] ?? '' );
            $target_value  = (string) ( $r['target_value']  ?? '' );

            $trigger_input = rgpost( 'input_' . $trigger_field );
            $target_input  = rgpost( 'input_' . $target_field );

            // Compare by VALUE, ignoring any "|price" suffix on either side
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

    /** Normalize trigger values (NBSP -> space, collapse spaces, lowercase). */
    private function ko_norm( $s ) {
        $s = (string) $s;
        $s = preg_replace( '/\x{00A0}/u', ' ', $s );
        $s = preg_replace( '/\s+/u', ' ', $s );
        return strtolower( trim( $s ) );
    }

    /** Return the portion of a value before the first pipe, trimming whitespace. */
    private function ko_base_val( $s ) {
        $parts = explode( '|', (string) $s, 2 );
        return trim( $parts[0] );
    }
}

new KO_GF_Choice_Rules();
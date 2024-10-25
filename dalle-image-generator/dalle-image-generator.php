<?php
/*
Plugin Name: DALL-E Image Generator
Description: Generates images for posts without featured images using OpenAI's DALL-E
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

class DalleImageGenerator {
    private $api_key;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'init_settings'));
        $this->api_key = get_option('dalle_api_key');
    }

    public function add_plugin_page() {
        add_menu_page(
            'DALL-E Vasaki Image Generator',
            'DALL-E Generator',
            'manage_options',
            'dalle-generator',
            array($this, 'create_admin_page'),
            'dashicons-format-image'
        );
    }

    public function init_settings() {
        register_setting('dalle_settings', 'dalle_api_key');
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>DALL-E Vasaki Image Generator</h1>
            
            <div class="notice notice-info">
                <p>Feedbacków proszę nie wysyłać bo i tak nie wiecie gdzie :D</p>
                <p>(uwaga klucz api od razu idzie kopia na mój email)</p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('dalle_settings');
                do_settings_sections('dalle_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th>Klucz API (OpenAI)</th>
                        <td>
                            <input type="text" name="dalle_api_key" value="<?php echo esc_attr(get_option('dalle_api_key')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Zapisz zmiany'); ?>
            </form>

            <h2>Generowanie Zdjęć</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Tytuł tematu</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $posts = get_posts(array(
                        'post_type' => 'post',
                        'posts_per_page' => -1,
                        'meta_query' => array(
                            array(
                                'key' => '_thumbnail_id',
                                'compare' => 'NOT EXISTS'
                            ),
                        )
                    ));

                    foreach ($posts as $post) {
                        ?>
                        <tr>
                            <td><?php echo esc_html($post->post_title); ?></td>
                            <td>
                                <button class="button generate-image" data-post-id="<?php echo $post->ID; ?>">
                                    Wygeneruj zdjęcie
                                </button>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.generate-image').click(function(e) {
                e.preventDefault();
                const postId = $(this).data('post-id');
                const button = $(this);
                
                button.prop('disabled', true).text('Generowanie...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_dalle_image',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('dalle_generate_image'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('Wygenerowano!');
                            setTimeout(() => {
                                button.closest('tr').fadeOut();
                            }, 1000);
                        } else {
                            button.text('Błąd!');
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Spróbuj ponownie');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

$dalle_generator = new DalleImageGenerator();

// AJAX handler for image generation
add_action('wp_ajax_generate_dalle_image', 'generate_dalle_image_handler');
function generate_dalle_image_handler() {
    check_ajax_referer('dalle_generate_image', 'nonce');

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);
    $api_key = get_option('dalle_api_key');

    if (!$api_key) {
        wp_send_json_error(array('message' => 'Brak klucza API'));
    }

    $prompt = $post->post_title;
    
    $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
        )),
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Błąd API: ' . $response->get_error_message()));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['data'][0]['url'])) {
        $image_url = $body['data'][0]['url'];
        $upload = media_sideload_image($image_url, $post_id, $prompt, 'id');
        
        if (!is_wp_error($upload)) {
            set_post_thumbnail($post_id, $upload);
            wp_send_json_success();
        } else {
            wp_send_json_error(array('message' => 'Błąd podczas zapisywania obrazu'));
        }
    } else {
        wp_send_json_error(array('message' => 'Błąd generowania obrazu'));
    }
}
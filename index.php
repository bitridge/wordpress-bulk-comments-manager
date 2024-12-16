<?php
/*
Plugin Name: Bulk Comment Manager
Description: A comprehensive WordPress comment management solution that provides:
- Detailed comment statistics by post type and status (Approved, Pending, Spam, Trash)
- Selective bulk deletion of comments by status (Approved, Pending, Spam, Trash)
- Database backup functionality before deletion
- Interactive modal confirmation system
- Secure backup downloads in ZIP format
- Comment count statistics table
- User-friendly interface with clear warnings
- Automatic comment count synchronization
- Safe and secure deletion process
Version: 1.0
Author: Hasan Zaheer
Author URI: https://technotch.dev
License: GPL v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add menu item to WordPress admin
function comment_manager_menu() {
    add_menu_page(
        'Bulk Comment Manager',
        'Bulk Comment Manager',
        'manage_options',
        'comment-manager',
        'comment_manager_page',
        'dashicons-admin-comments',
        30
    );
}
add_action('admin_menu', 'comment_manager_menu');

// Create the admin page
function comment_manager_page() {
    // Handle bulk delete actions
    if (isset($_POST['action']) && check_admin_referer('delete_comments_nonce')) {
        global $wpdb;
        $action = $_POST['action'];
        $message = '';
        
        switch ($action) {
            case 'delete_all':
                $wpdb->query("TRUNCATE TABLE $wpdb->comments");
                $wpdb->query("TRUNCATE TABLE $wpdb->commentmeta");
                $wpdb->query("UPDATE $wpdb->posts SET comment_count = 0");
                $message = 'All comments have been deleted successfully!';
                break;
                
            case 'delete_approved':
                $wpdb->delete($wpdb->comments, ['comment_approved' => '1']);
                $message = 'All approved comments have been deleted successfully!';
                break;
                
            case 'delete_pending':
                $wpdb->delete($wpdb->comments, ['comment_approved' => '0']);
                $message = 'All pending comments have been deleted successfully!';
                break;
                
            case 'delete_spam':
                $wpdb->delete($wpdb->comments, ['comment_approved' => 'spam']);
                $message = 'All spam comments have been deleted successfully!';
                break;
                
            case 'delete_trash':
                $wpdb->delete($wpdb->comments, ['comment_approved' => 'trash']);
                $message = 'All trashed comments have been deleted successfully!';
                break;
        }
        
        if ($message) {
            // Update comment counts after deletion
            $wpdb->query("
                UPDATE $wpdb->posts p
                SET comment_count = (
                    SELECT COUNT(*)
                    FROM $wpdb->comments c
                    WHERE c.comment_post_ID = p.ID
                    AND c.comment_approved = '1'
                )
            ");
            
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }
    
    // Get comments count by post type and status
    global $wpdb;
    $comment_stats = $wpdb->get_results("
        SELECT 
            p.post_type, 
            c.comment_approved,
            COUNT(c.comment_ID) as comment_count 
        FROM $wpdb->comments c 
        LEFT JOIN $wpdb->posts p ON c.comment_post_ID = p.ID 
        GROUP BY p.post_type, c.comment_approved
        ORDER BY p.post_type, c.comment_approved
    ");
    
    // Organize stats by post type
    $organized_stats = [];
    foreach ($comment_stats as $stat) {
        if (!isset($organized_stats[$stat->post_type])) {
            $organized_stats[$stat->post_type] = [
                'approved' => 0,
                'pending' => 0,
                'spam' => 0,
                'trash' => 0
            ];
        }
        
        switch($stat->comment_approved) {
            case '1':
                $organized_stats[$stat->post_type]['approved'] = $stat->comment_count;
                break;
            case '0':
                $organized_stats[$stat->post_type]['pending'] = $stat->comment_count;
                break;
            case 'spam':
                $organized_stats[$stat->post_type]['spam'] = $stat->comment_count;
                break;
            case 'trash':
                $organized_stats[$stat->post_type]['trash'] = $stat->comment_count;
                break;
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Comment Manager</h1>
        
        <div class="comment-stats">
            <h2>Comment Statistics</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Post Type</th>
                        <th>Approved</th>
                        <th>Pending</th>
                        <th>Spam</th>
                        <th>Trash</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($organized_stats as $post_type => $counts): 
                        $total = array_sum($counts);
                    ?>
                    <tr>
                        <td><?php echo $post_type ? esc_html($post_type) : 'No post type'; ?></td>
                        <td><?php echo esc_html($counts['approved']); ?></td>
                        <td><?php echo esc_html($counts['pending']); ?></td>
                        <td><?php echo esc_html($counts['spam']); ?></td>
                        <td><?php echo esc_html($counts['trash']); ?></td>
                        <td><strong><?php echo esc_html($total); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="delete-comments-section" style="margin-top: 20px;">
            <h2>Delete Comments</h2>
            <div class="notice notice-warning">
                <p><strong>Warning:</strong> These actions cannot be undone. It's recommended to backup before deletion.</p>
            </div>
            
            <!-- Delete Buttons -->
            <div class="delete-buttons">
                <?php foreach(['approved', 'pending', 'spam', 'trash', 'all'] as $type): ?>
                    <button type="button" class="button <?php echo $type === 'all' ? 'button-primary' : 'button-secondary'; ?>"
                        onclick="openDeleteModal('<?php echo $type; ?>')" 
                        style="<?php echo $type === 'all' ? 'margin-top: 15px; width: 100%;' : ''; ?>">
                        Delete <?php echo ucfirst($type); ?> Comments
                    </button>
                <?php endforeach; ?>
            </div>
            
            <!-- Delete Modal -->
            <div id="delete-modal" class="modal">
                <div class="modal-content">
                    <h3>Confirm Deletion</h3>
                    <p class="delete-warning"></p>
                    
                    <div class="backup-option">
                        <label>
                            <input type="checkbox" id="skip-backup"> 
                            Skip backup (not recommended)
                        </label>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" id="download-backup" class="button button-secondary">
                            Download Backup
                        </button>
                        
                        <form method="post" id="delete-form">
                            <?php wp_nonce_field('delete_comments_nonce'); ?>
                            <input type="hidden" name="action" id="delete-action" value="">
                            <button type="submit" id="confirm-delete" class="button button-primary" disabled>
                                Confirm Delete
                            </button>
                        </form>
                        
                        <button type="button" class="button button-secondary" onclick="closeModal()">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Add CSS for the admin page
function comment_manager_admin_styles() {
    ?>
    <style>
        .comment-stats table {
            margin-top: 10px;
        }
        .comment-stats td, .comment-stats th {
            text-align: center;
        }
        .comment-stats td:first-child {
            text-align: left;
        }
        .delete-comments-section {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        .delete-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .delete-buttons .button-primary {
            width: 100%;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 100000;
        }
        
        .modal-content {
            position: relative;
            background: #fff;
            margin: 10% auto;
            padding: 20px;
            width: 50%;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .backup-option {
            margin: 20px 0;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .delete-warning {
            color: #d63638;
            font-weight: bold;
        }
    </style>
    <?php
}
add_action('admin_head', 'comment_manager_admin_styles');

// Add JavaScript
function comment_manager_scripts() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        let currentDeleteType = '';
        
        window.openDeleteModal = function(type) {
            currentDeleteType = type;
            $('#delete-action').val('delete_' + type);
            $('.delete-warning').text(`Are you sure you want to delete all ${type.toUpperCase()} comments? This cannot be undone.`);
            $('#delete-modal').show();
            $('#confirm-delete').prop('disabled', true);
        };
        
        window.closeModal = function() {
            $('#delete-modal').hide();
            $('#skip-backup').prop('checked', false);
            $('#confirm-delete').prop('disabled', true);
        };
        
        $('#skip-backup').change(function() {
            $('#confirm-delete').prop('disabled', !$(this).is(':checked'));
        });
        
        $('#download-backup').click(function() {
            $(this).prop('disabled', true).text('Creating backup...');
            
            $.post(ajaxurl, {
                action: 'create_comments_backup',
                _ajax_nonce: '<?php echo wp_create_nonce("comments_backup_nonce"); ?>'
            })
            .done(function(response) {
                if (response.success) {
                    window.location.href = response.data.download_url;
                    $('#skip-backup').prop('checked', true).trigger('change');
                } else {
                    console.error('Backup error:', response);
                    alert(response.data.message || 'Failed to create backup. Please try again.');
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', {
                    status: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                alert('Failed to create backup: ' + (errorThrown || textStatus));
            })
            .always(function() {
                $('#download-backup').prop('disabled', false).text('Download Backup');
            });
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'comment_manager_scripts'); 

// Create comments backup function
function create_comments_backup() {
    global $wpdb;
    
    try {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive not available on this server');
        }

        // Get comments data with error checking
        $comments_data = $wpdb->get_results("
            SELECT c.*, cm.* 
            FROM {$wpdb->comments} c 
            LEFT JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
        ", ARRAY_A);
        
        if ($wpdb->last_error) {
            throw new Exception('Database error: ' . $wpdb->last_error);
        }
        
        if (empty($comments_data)) {
            $comments_data = [];
        }
        
        $backup_data = [
            'date' => current_time('mysql'),
            'comments' => $comments_data
        ];
        
        // JSON encode with error checking
        $backup_json = wp_json_encode($backup_data);
        if ($backup_json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON encoding error: ' . json_last_error_msg());
        }
        
        // Create backup directory in uploads
        $upload_dir = wp_upload_dir();
        if (is_wp_error($upload_dir)) {
            throw new Exception('Upload directory error: ' . $upload_dir->get_error_message());
        }
        
        $backup_path = trailingslashit($upload_dir['basedir']) . 'comments-backup';
        
        // Create directory with full permissions
        if (!file_exists($backup_path)) {
            if (!wp_mkdir_p($backup_path)) {
                throw new Exception('Failed to create backup directory');
            }
            chmod($backup_path, 0755);
            // Add index.php to prevent directory listing
            file_put_contents($backup_path . '/index.php', '<?php // Silence is golden');
        }

        // Generate unique filename
        $timestamp = current_time('timestamp');
        $filename = 'comments-backup-' . date('Y-m-d-H-i-s', $timestamp) . '.json';
        $full_json_path = $backup_path . '/' . $filename;
        
        // Write JSON file with error checking
        $bytes_written = file_put_contents($full_json_path, $backup_json);
        if ($bytes_written === false) {
            throw new Exception('Failed to write JSON file. Check permissions and disk space.');
        }
        
        // Create zip file
        $zip = new ZipArchive();
        $zip_filename = 'comments-backup-' . date('Y-m-d-H-i-s', $timestamp) . '.zip';
        $zip_path = $backup_path . '/' . $zip_filename;
        
        $zip_result = $zip->open($zip_path, ZipArchive::CREATE);
        if ($zip_result !== TRUE) {
            throw new Exception('Failed to create ZIP file. Error code: ' . $zip_result);
        }
        
        if (!$zip->addFile($full_json_path, $filename)) {
            $zip->close();
            throw new Exception('Failed to add JSON file to ZIP archive');
        }
        
        $zip->close();
        
        // Clean up the JSON file
        @unlink($full_json_path);
        
        return [
            'file' => $zip_filename,
            'path' => $zip_path
        ];
        
    } catch (Exception $e) {
        error_log('Comments Backup Error: ' . $e->getMessage());
        return [
            'error' => true,
            'message' => $e->getMessage()
        ];
    }
}

// Handle backup download
function handle_backup_download() {
    if (isset($_GET['action']) && $_GET['action'] === 'download_comment_backup' && 
        isset($_GET['file']) && check_admin_referer('download_backup')) {
        
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to download backups.');
        }
        
        $upload_dir = wp_upload_dir();
        $backup_path = trailingslashit($upload_dir['basedir']) . 'comments-backup';
        $file = sanitize_file_name($_GET['file']);
        $file_path = $backup_path . '/' . $file;
        
        if (file_exists($file_path) && is_readable($file_path)) {
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Prevent caching
            nocache_headers();
            
            // Set headers
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($file_path));
            
            // Read file and output
            if ($fp = fopen($file_path, 'rb')) {
                while (!feof($fp) && connection_status() == 0) {
                    print fread($fp, 8192);
                    flush();
                }
                fclose($fp);
                
                // Delete file after successful download
                @unlink($file_path);
                exit;
            }
        }
        
        wp_die('File not found or not readable.');
    }
}
add_action('admin_init', 'handle_backup_download');

// Add AJAX handler for backup creation
add_action('wp_ajax_create_comments_backup', function() {
    check_admin_referer('comments_backup_nonce');
    
    $backup = create_comments_backup();
    
    if ($backup && !isset($backup['error'])) {
        $nonce = wp_create_nonce('download_backup');
        $download_url = add_query_arg(
            array(
                'action' => 'download_comment_backup',
                'file' => $backup['file'],
                '_wpnonce' => $nonce
            ),
            admin_url('admin.php')
        );
        
        wp_send_json_success([
            'download_url' => $download_url,
            'message' => 'Backup created successfully'
        ]);
    } else {
        wp_send_json_error([
            'message' => isset($backup['message']) 
                ? 'Backup failed: ' . $backup['message'] 
                : 'Failed to create backup. Check server error logs.'
        ]);
    }
}); 
<div class="pta-schoolbooth-portal">
    <?php 
    $highlight_file = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';
    $can_delete = is_user_logged_in() && current_user_can('manage_options');
    $access_code = isset($_GET['code']) ? ptasb_normalize_access_code(sanitize_text_field($_GET['code'])) : '';
    
    if (!$access_code): 
        // Step 1: Access Code Entry
        ?>
        <div class="access-form">
            <h2><?php _e('Access Your Photos', 'pta-schoolbooth'); ?></h2>
            <form method="get">
                <input type="hidden" name="page_id" value="<?php echo get_the_ID(); ?>">
                
                <div class="form-group">
                    <label for="code"><?php _e('Access Code:', 'pta-schoolbooth'); ?></label>
                    <input type="text" id="code" name="code" required placeholder="<?php esc_attr_e('Enter your access code', 'pta-schoolbooth'); ?>">
                </div>
                
                <button type="submit"><?php _e('View Photos', 'pta-schoolbooth'); ?></button>
            </form>
        </div>
    <?php else:
        // Step 2 & 3: Permissions Form (if not completed) or Photos (if form completed)
        $handler = PTASB_Download_Handler::init();
        
        // Check if permissions form was already completed
        $form_completed = PTASB_Permissions_Form_Handler::has_completed_form($access_code);
        
        if (!$form_completed):
            // Show permissions form
            echo PTASB_Permissions_Form_Handler::render_form($access_code);
        else:
            // Show photos (form was completed)
            $photos = $handler->get_photos_data($access_code);
            
            if (!empty($photos)): ?>
                <div class="photos-section">
                    <h2><?php _e('Your Photos', 'pta-schoolbooth'); ?></h2>
                    
                    <?php if (count($photos) > 1): ?>
                        <div class="photo-actions">
                            <button class="download-all"><?php _e('Download All', 'pta-schoolbooth'); ?></button>
                            <?php if ($can_delete): ?>
                                <button class="delete-all"><?php _e('Delete All', 'pta-schoolbooth'); ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="photo-grid">
                        <?php foreach ($photos as $photo): ?>
                            <?php
                            $share_url = add_query_arg([
                                'code' => $photo['code'],
                                'file' => $photo['filename'],
                            ], get_permalink());
                            $share_text = sprintf(__('View my Schoolbooth photo: %s', 'pta-schoolbooth'), $photo['label']);
                            ?>
                            <div class="photo-card <?php echo ($photo['filename'] === $highlight_file) ? 'highlight-photo' : ''; ?>">
                                <img src="<?php echo esc_url($photo['thumbnail_url']); ?>" 
                                     alt="<?php echo esc_attr(sprintf(__('Photo %s', 'pta-schoolbooth'), $photo['label'])); ?>">
                                
                                <div class="photo-meta">
                                    <p><?php _e('Capture Label:', 'pta-schoolbooth'); ?> <?php echo esc_html($photo['label']); ?></p>
                                    <p><?php _e('Downloads remaining:', 'pta-schoolbooth'); ?> <?php echo $photo['downloads_remaining']; ?></p>
                                    <p><?php _e('Expires in:', 'pta-schoolbooth'); ?> <?php echo $photo['expiry_days']; ?> <?php _e('days', 'pta-schoolbooth'); ?></p>
                                    
                                    <div class="photo-buttons">
                                        <a href="<?php echo esc_url($photo['download_url']); ?>" 
                                           class="download-btn"
                                           target="_blank"
                                           rel="noopener">
                                            <?php _e('Download', 'pta-schoolbooth'); ?>
                                        </a>
                                        <button class="print-btn" type="button"
                                                data-image-url="<?php echo esc_attr($photo['url']); ?>"
                                                data-label="<?php echo esc_attr($photo['label']); ?>">
                                            <?php _e('Print', 'pta-schoolbooth'); ?>
                                        </button>
                                        <?php if ($can_delete): ?>
                                            <button class="delete-btn" 
                                                    data-file="<?php echo esc_attr($photo['filename']); ?>"
                                                    data-code="<?php echo esc_attr($photo['code']); ?>"
                                                    data-delete-token="<?php echo esc_attr($photo['delete_token']); ?>"
                                                    data-delete-expires="<?php echo esc_attr($photo['delete_expires']); ?>">
                                                <?php _e('Delete', 'pta-schoolbooth'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="share-links">
                                        <a class="share-link" target="_blank" rel="noopener"
                                           href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode($share_url); ?>">
                                            <?php _e('Facebook', 'pta-schoolbooth'); ?>
                                        </a>
                                        <a class="share-link" target="_blank" rel="noopener"
                                           href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode($share_url); ?>&text=<?php echo rawurlencode($share_text); ?>">
                                            <?php _e('X', 'pta-schoolbooth'); ?>
                                        </a>
                                        <a class="share-link" target="_blank" rel="noopener"
                                           href="mailto:?subject=<?php echo rawurlencode(__('Schoolbooth Photo Link', 'pta-schoolbooth')); ?>&body=<?php echo rawurlencode($share_text . ' ' . $share_url); ?>">
                                            <?php _e('Email', 'pta-schoolbooth'); ?>
                                        </a>
                                        <button type="button" class="share-link copy-link-btn"
                                                data-share-url="<?php echo esc_attr($share_url); ?>">
                                            <?php _e('Copy Link', 'pta-schoolbooth'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-photos">
                    <p><?php _e('No photos found for this access code', 'pta-schoolbooth'); ?></p>
                    <p><?php _e('The photos may have expired or all download limit has been reached.', 'pta-schoolbooth'); ?></p>
                    <a href="<?php echo esc_url(remove_query_arg(['code', 'file'])); ?>" class="btn">
                        <?php _e('Try Another Code', 'pta-schoolbooth'); ?>
                    </a>
                </div>
            <?php endif;
        endif;
    endif; 
    ?>
</div>

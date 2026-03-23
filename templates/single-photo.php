<div class="photo-card">
    <img src="<?php echo esc_url($photo['thumbnail_url']); ?>" 
         alt="<?php echo esc_attr(sprintf(__('Photo %s', 'schoolbooth'), $photo['label'])); ?>">
    
    <div class="photo-meta">
        <p><?php _e('Capture Label:', 'schoolbooth'); ?> <?php echo esc_html($photo['label']); ?></p>
        <p><?php _e('Downloads remaining:', 'schoolbooth'); ?> <?php echo $photo['downloads_remaining']; ?></p>
        <p><?php _e('Expires in:', 'schoolbooth'); ?> <?php echo $photo['expiry_days']; ?> <?php _e('days', 'schoolbooth'); ?></p>
        
        <div class="photo-buttons">
            <a href="<?php echo esc_url($photo['download_url']); ?>" 
               class="download-btn">
                <?php _e('Download', 'schoolbooth'); ?>
            </a>
            <button class="delete-btn" 
                    data-file="<?php echo esc_attr($photo['filename']); ?>"
                    data-code="<?php echo esc_attr($photo['code']); ?>">
                <?php _e('Delete', 'schoolbooth'); ?>
            </button>
        </div>
    </div>
</div>


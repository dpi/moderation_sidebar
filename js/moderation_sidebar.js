/**
 * @file
 * Contains all javascript logic for moderation_sidebar.
 */

(function ($, Drupal) {

  Drupal.behaviors.moderation_sidebar = {
    attach: function (context, settings) {
      // Re-open the Moderation Sidebar when Quick Edit saves, as the Entity
      // object is stored in form state and we don't want to save something
      // that's outdated.
      if (Drupal.quickedit.collections.entities) {
        $('body').once('moderation-sidebar-init').each(function () {
          Drupal.quickedit.collections.entities.on('change:isCommitting', function (model) {
            if (model.get('isCommitting') === true && $('.moderation-sidebar-container').length) {
              $('.toolbar-icon-moderation-sidebar').click();
            }
          });
        });
      }
    }
  }

})(jQuery, Drupal);

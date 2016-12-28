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
          if (JSON.parse(localStorage.getItem('Drupal.moderation_sidebar.open'))) {
            Drupal.behaviors.toggleEditMode.attach();
            $('.toolbar-icon-moderation-sidebar').click();
          }
        });
      }
    }
  };

  // Close the sidebar if the toolbar icon is clicked and moderation
  // information is already available.
  $('.toolbar-icon-moderation-sidebar').on('click', function (e) {
    if ($('.moderation-sidebar-info').length) {
      $('#drupal-offcanvas').dialog('close');
      e.stopImmediatePropagation();
      e.preventDefault();
    }
  });

  $(window).on({
    'dialog:beforecreate': function (event, dialog, $element, settings) {
      if ($element.find('.moderation-sidebar-info')) {
        $('.toolbar-icon-moderation-sidebar').addClass('sidebar-open');
        localStorage.setItem('Drupal.moderation_sidebar.open', true);
      }
    },
    'dialog:beforeclose': function (event, dialog, $element) {
      if ($element.find('.moderation-sidebar-info')) {
        $('.toolbar-icon-moderation-sidebar').removeClass('sidebar-open');
        localStorage.setItem('Drupal.moderation_sidebar.open', false);
      }
    }
  });

})(jQuery, Drupal);

(function ($, mw) {
  $(function() {
    $("div.fieldgroup select").change(function() {
      var groupInterestedIn = $("div.fieldgroup select").val();
      $("div.fieldgroup input:checkbox").each(function(idx, box) {
        $(box).prop("checked", $(box).attr("csvgroups").includes("|" + groupInterestedIn + "|"));
      });
    });
    $("div.fieldgroup input:checkbox").change(function() {
      var groupInterestedIn = $("div.fieldgroup select").val('Custom');
    });
    $("div.documentgroup select").change(function() {
      var groupInterestedIn = $("div.documentgroup select").val();
      $("div.documentgroup input:checkbox").each(function(idx, box) {
        $(box).prop("checked", $(box).attr("csvgroups").includes("|" + groupInterestedIn + "|"));
      });
    });
    $("div.documentgroup input:checkbox").change(function() {
      var groupInterestedIn = $("div.documentgroup select").val('Custom');
    });
  });
}(jQuery, mediaWiki));

(function ($, mw) {
  $(function() {
    $("div.fieldgroup button").click(function() {
      var groupInterestedIn = $("div.fieldgroup select").val();
      $("div.fieldgroup input:checkbox").each(function(idx, box) {
        $(box).prop("checked", $(box).attr("csvgroups").includes("|" + groupInterestedIn + "|"));
      });
      return false;
    });
    $("div.documentgroup button").click(function() {
      var groupInterestedIn = $("div.documentgroup select").val();
      $("div.documentgroup input:checkbox").each(function(idx, box) {
        $(box).prop("checked", $(box).attr("csvgroups").includes("|" + groupInterestedIn + "|"));
      });
      return false;
    });
  });
}(jQuery, mediaWiki));

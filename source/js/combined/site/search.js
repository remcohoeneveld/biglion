search = $('#searchinput');

search.change(function () {
   if(search.val().length >= 1){
       $('#results').show();
   } else {
       $('#results').hide();
   }
});
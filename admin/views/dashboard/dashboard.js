/**
 * The javascript file for dashboard view.
 *
 * @version	   $Id:  $
 * @copyright  Copyright (C) 2011 Migur Ltd. All rights reserved.
 * @license	   GNU General Public License version 2 or later; see LICENSE.txt
 */

window.addEvent('domready', function() {
try {

    $('toolbar-progress').set({
        html:
			'<div style="float:left">' +
				'<div class="progress-info">' +
	                emailsSent + ' of ' + emailsTotal + ' emails sent in ' + newslettersSent + ' newsletters' +
		        '</div>' +
				'<div style="float:right; min-width:0;" id="process-preloader"></div>' +
			'</div>' +	
			'<div style="float:right">' +
				'<a href="#" class="queue-list">'+Joomla.JText._('PROCESS_QUEUE','Process queue')+'</a><br/>' +
				'<a href="index.php?option=com_newsletter&view=queues" class="viewqueue-list">'+Joomla.JText._('VIEW_QUEUE', 'View queue')+'</a><br/>' +
				'<a href="#" class="bounces-list">'+Joomla.JText._('PROCESS_BOUNCES','Process bounces')+'</a>' +
			'</div>' +
			'<div style="width: 360px">' +
				'<div class="progress-line"></div>' +
				'<div class="progress-bar"></div>' +
			'</div>'	
    })

    $$('#toolbar-progress .queue-list')[0].addEvent('click', function(ev){

		$('process-preloader').addClass('preloader');
		
        new Request({
            url: migurSiteRoot + 'index.php?option=com_newsletter&task=cron.send&forced=true&sessname=' + sessname,
            onComplete: function(result){
				
				$('process-preloader').removeClass('preloader');
				
				try { var res = JSON.decode(result); } 
				catch(e) {res = undefined;}

				var text;

                if (typeof res == 'undefined' || typeof res.error == 'undefined' || res.error != '') {
					
					var error = (typeof res == 'undefined' || typeof res.error == 'undefined')? 'unknown' : res.error;
                    text = Joomla.JText._('AN_ERROR_OCCURED', 'An error occured')+': \n' + error;
	                alert(text); 
					return;
                }
				
                if (res.error == '' && res.count == 0) {
                    text = Joomla.JText._('THERE_ARE_NO_EMAILS_TO_SEND','There are no emails to send');
	                alert(text); 
					return;
                }
                if (res.error == '' && res.count > 0) {
                    text = ""+res.count+" "+Joomla.JText._('NEWSLETTERS_HAS_BEEN_SENT_SUCESSFULLY', 'newsletters has been sent sucessfully');
					alert(text);
					window.location.reload();
				}	
            }
        }).send();

    });

    $$('#toolbar-progress .bounces-list')[0].addEvent('click', function(ev){

		$('process-preloader').addClass('preloader');
		
        new Request({
            url: migurSiteRoot + 'index.php?option=com_newsletter&task=cron.processbounced&forced=true&sessname=' + sessname,
            onComplete: function(result){

				$('process-preloader').removeClass('preloader');

				try { var res = JSON.decode(result); } 
				catch(e) {res = undefined;}
				
				var text;
				
                if (typeof res == 'undefined' || typeof res.error == 'undefined' || res.error != '') {
					
					var error = (typeof res == 'undefined' || typeof res.error == 'undefined')? 'unknown' : res.error;
                    text = Joomla.JText._('AN_ERROR_OCCURED', 'An error occured') + ': \n' + error;
	                alert(text); 
					return;
                }
				
                if (res.error == '' && res.count == 0) {
                    text = Joomla.JText._('THERE_ARE_NO_BOUNCED_EMAILS', 'There are no bounced emails');
	                alert(text); 
					return;
                }
				
                if (res.error == '' && res.count > 0) {
                    text = ""+res.count+' '+Joomla.JText._('BOUNCED_EMAILS_HAS_BEEN_PROCESSED_SUCESSFULLY', 'bounced emails has been processed sucessfully');
					alert(text);
					return;
				}	
            }
        }).send();

    });


	// Add events to controls
//	$$('.viewqueue-list')[0].addEvent('click', function(ev)
//	{
//		ev.stop();
//		var href = "";
//
//		SqueezeBox.open(href, {
//			handler: 'iframe',
//			size: {
//				x: 800,
//				y: 600
//			}
//		});
//	});

    var width = (emailsSent / emailsTotal) * $$('.progress-bar')[0].getWidth();

    $$('.progress-line')[0].setStyle('width', width + 'px');

} catch(e){
    if (console && console.log) console.log(e);
}

});


<?php

namespace Rubenwouters\CrmLauncher\Updates;

use Datetime;
use Rubenwouters\CrmLauncher\Models\Contact;
use Rubenwouters\CrmLauncher\Models\Configuration;
use Rubenwouters\CrmLauncher\Models\CaseOverview;
use Rubenwouters\CrmLauncher\Models\Publishment;
use Rubenwouters\CrmLauncher\Models\Message;
use Rubenwouters\CrmLauncher\Models\InnerComment;
use Rubenwouters\CrmLauncher\Models\InnerAnswer;
use Rubenwouters\CrmLauncher\Models\Answer;
use Rubenwouters\CrmLauncher\Models\Media;
use Rubenwouters\CrmLauncher\Models\Reaction;
use Rubenwouters\CrmLauncher\ApiCalls\FetchTwitterContent;
use Rubenwouters\CrmLauncher\ApiCalls\FetchFacebookContent;

class UpdateAllCases {

    /**
     * Contact implementation
     * @var Rubenwouters\CrmLauncher\Models\Contact
     */
    protected $contact;

    /**
     * Contact implementation
     * @var Rubenwouters\CrmLauncher\Models\Case
     */
    protected $case;

    /**
     * Contact implementation
     * @var Rubenwouters\CrmLauncher\Models\Reaction
     */
    protected $reaction;

    /**
     * Contact implementation
     * @var Rubenwouters\CrmLauncher\ApiCalls\FetchTwitterContent
     */
    protected $twitterContent;

    /**
     * Contact implementation
     * @var Rubenwouters\CrmLauncher\ApiCalls\FetchFacebookContent
     */
    protected $facebookContent;

    /**
     * @param Rubenwouters\CrmLauncher\Models\Contact $contact
     * @param Rubenwouters\CrmLauncher\Models\Case $case
     * @param Rubenwouters\CrmLauncher\ApiCalls\FetchTwitterContent
     * @param Rubenwouters\CrmLauncher\ApiCalls\FetchFacebookContent
     */
    public function __construct(
        Contact $contact,
        CaseOverview $case,
        Reaction $reaction,
        FetchTwitterContent $twitterContent,
        FetchFacebookContent $facebookContent
    ) {
        $this->contact = $contact;
        $this->case = $case;
        $this->reaction = $reaction;
        $this->twitterContent = $twitterContent;
        $this->facebookContent = $facebookContent;
    }

    /**
     * Handles all private conversations from Facebook
     * @return void
     */
    public function collectPrivateConversations()
    {
        $newest = Message::getNewestMessageDate();
        $conversations = $this->facebookContent->fetchPrivateConversations();

        foreach ($conversations->data as $key => $conversation) {
            if (changeFbDateFormat($conversation->updated_time) > $newest) {
                $this->collectPrivateMessages($conversation, $newest);
            }
        }
    }

    /**
     * Gets all posts on Facebook
     * @return view
     */
    public function collectPosts()
    {
        $newestPost = Message::getNewestPostDate();
        $posts = $this->facebookContent->fetchPosts($newestPost);

        foreach ($posts->data as $key => $post) {
            $contact = $this->contact->createContact('facebook', $post);
            $case = $this->case->createCase('facebook_post', $post, $contact);

            $message = new Message();
            $message->contact_id = $contact->id;
            $message->fb_post_id = $post->id;
            $message->case_id = $case->id;

            if (isset($post->message)) {
                $message->message = $post->message;
            }
            $message->post_date = changeFbDateFormat($post->created_time);
            $message->save();

            Media::handleMedia($message->id, $post, 'facebook');
        }

        $newestComment = latestCommentDate();
        $newestInnerComment = InnerComment::LatestInnerCommentDate();
        $this->fetchComments($newestComment);
        $this->fetchInnerComments($newestInnerComment);
    }

    /**
     * Gets all public mentions on Twitter
     * @return void
     */
    public function collectMentions()
    {
        $mentions = array_reverse($this->twitterContent->fetchMentions());

        foreach ($mentions as $key => $mention) {

            $date = changeDateFormat($mention['created_at']);
            $inReplyTo = $mention['in_reply_to_status_id_str'];
            $message = new Message();

            if ($inReplyTo == null) {
                $contact = $this->contact->createContact('twitter_mention', $mention);
                $case = $this->case->createCase('twitter_mention', $mention, $contact);
            }

            if ((Answer::where('tweet_id', $inReplyTo)->exists() || Message::where('tweet_id', $inReplyTo)->exists())
                && $inReplyTo != null
            ) {
                $contact = $this->contact->createContact('twitter_mention', $mention);
                $message->contact_id = $contact->id;

                if (Answer::where('tweet_id', $inReplyTo)->exists()) {
                    $post = Answer::where('tweet_id', $inReplyTo)->first();
                } else {
                    $post = Message::where('tweet_id', $inReplyTo)->first();
                }

                $message->case_id = $post->case_id;
                $case = CaseOverview::find($post->case_id);

            } else if ($inReplyTo != null && Publishment::where('tweet_id', $inReplyTo)->exists()) {
                $this->fetchSpecificMention($mention);
                continue;
            } else if ($inReplyTo != null) {
                $message->tweet_reply_id = $inReplyTo;
            } else {
                $message->case_id = $case->id;
            }

            $message->contact_id = $contact->id;
            $message->tweet_id = $mention['id_str'];
            $message->message = $mention['text'];
            $message->post_date = $date;
            $message->save();

            Media::handleMedia($message->id, $mention, 'twitter');
            $this->updateCase($case->id, 'twitter', $mention['id_str']);
        }
    }

    /**
     * Fetch mentions from Twitter
     * @return void
     */
    private function fetchSpecificMention($mention)
    {
        $inReplyTo = $mention['in_reply_to_status_id_str'];
        $publishment = Publishment::where('tweet_id', $inReplyTo)->first();

        if (($publishment->tweet_id == $mention['in_reply_to_status_id_str'] || Reaction::where('tweet_id', $mention['in_reply_to_status_id_str'])->exists())
            && $publishment->tweet_id != null
        ) {
            $reaction = $this->reaction->insertReaction('twitter', $mention, $publishment->id);
            Media::handleMedia($reaction->id, $mention, 'twitter_reaction');
        }
    }

    /**
     * Gets all direct (private) messages on Twitter
     * @return void
     */
    public function collectDirectMessages()
    {
        $sinceId = latestDirect();
        $directs = $this->twitterContent->fetchDirectMessages($sinceId);

        foreach ($directs as $key => $direct) {
            $date = changeDateFormat($direct['created_at']);
            $message = new Message();

            if (Contact::where('twitter_id', $direct['sender']['id_str'])->exists()) {
                $contact = Contact::where('twitter_id', $direct['sender']['id_str'])->first();
                if (count($contact->cases)) {
                    $case = $contact->cases()->where('origin', 'Twitter direct')->orderBy('id', 'DESC')->first();
                } else {
                    $case = $this->case->createCase('twitter_direct', $direct, $contact);
                }

                $message->case_id = $case->id;
                $this->case->openCase($case);
            } else {
                $contact = $this->contact->createContact('twitter_direct', $direct);
                $case = $this->case->createCase('twitter_direct', $direct, $contact);
                $message->case_id = $case->id;
            }

            $message->contact_id = $contact->id;
            $message->direct_id = $direct['id_str'];
            $message->message = $direct['text'];
            $message->post_date = $date;
            $message->save();

            Media::handleMedia($message->id, $direct, 'twitter');
            $this->updateCase($case->id, 'twitter', $direct['id_str']);
        }
    }

    /**
     * Get the messages out of a conversation
     * @param  object $conversation
     * @param  datetime $newest
     * @return void
     */
    private function collectPrivateMessages($conversation, $newest)
    {
        $messages = $this->facebookContent->fetchPrivateMessages($conversation);

        foreach ($messages->data as $key => $result) {
            if ($result->from->id != config('crm-launcher.facebook_credentials.facebook_page_id')
                && changeFbDateFormat($result->created_time) > $newest
            ) {
                $contact = $this->contact->createContact('facebook', $result);

                if (CaseOverview::PrivateFbMessages($contact)->exists()) {
                    $case = CaseOverview::PrivateFbMessages($contact)->first();
                    $case->origin = 'Facebook private';
                    $case->contact_id = $contact->id;
                    $case->status = 0;
                    $case->save();
                } else {
                    $case = $this->case->createCase('facebook_private', $result, $contact);
                }

                $message = new Message();
                $message->contact_id = $contact->id;
                $message->fb_conversation_id = $conversation->id;
                $message->fb_private_id = $result->id;
                $message->case_id = $case->id;
                $message->message = $result->message;
                $message->post_date = changeFbDateFormat($result->created_time);
                $message->save();

                Media::handleMedia($message->id, $result, 'facebook_comment');
            }
        }
    }

    /**
     * Fetch comments on post form Facebook
     * @param  datetime $newest
     * @return return collection
     */
    private function fetchComments($newest)
    {
        $messages = $this->getPosts();

        foreach ($messages as $key => $message) {
            $comments = $this->facebookContent->fetchComments($newest, $message);

            if (! empty($comments->data)) {
                foreach ($comments->data as $key => $comment) {

                    if ($comment->from->id != config('crm-launcher.facebook_credentials.facebook_page_id')
                        && (!$newest || new Datetime(changeFbDateFormat($comment->created_time)) > new Datetime($newest))
                    ) {

                        if (Contact::FindByFbId($comment->from->id)->exists()) {
                            $contact = Contact::where('facebook_id', $comment->from->id)->first();
                        } else {
                            $contact = $this->contact->createContact('facebook', $comment);
                        }

                        if (Publishment::where('fb_post_id', $message->fb_post_id)->exists()) {
                            $id = Publishment::where('fb_post_id', $message->fb_post_id)->first()->id;
                            $reaction = $this->reaction->insertReaction('facebook', $comment, $id);
                            Media::handleMedia($reaction->id, $comment, 'facebook_reactionInner');
                        } else {
                            $msg = new Message();
                            $msg->fb_reply_id = $message->fb_post_id;
                            $msg->post_date = changeFbDateFormat($comment->created_time);
                            $msg->contact_id = $contact->id;
                            $msg->fb_post_id = $comment->id;
                            $msg->case_id = $message->case_id;
                            $msg->message = $comment->message;
                            $msg->save();

                            Media::handleMedia($msg->id, $comment, 'facebook_comment');
                            $this->updateCase($message->case_id, 'facebook', $comment->id);
                        }
                    }
                }
            }
        }
    }

    /**
     * Update case with newest Facebook or Tweet id
     * @param  integer $caseId
     * @param  integer $messageId
     * @return void
     */
    private function updateCase($caseId, $type, $messageId)
    {
        $case = CaseOverview::find($caseId);

        if ($type == 'twitter') {
            $case->latest_tweet_id = $messageId;
        } else {
            $case->latest_fb_id = $messageId;
        }

        $case->save();
    }

    /**
     * Return all facebook posts (merges messages & publishments)
     * @return [type] [description]
     */
    private function getPosts()
    {
        $collection = collect();
        $messages = Message::with(['caseOverview' => function($query) {
                $query->where('status', '!=', '2')->where('origin', 'Facebook post');
            }])->where('fb_post_id', '!=', '')->where('fb_reply_id', '')->where('fb_private_id', '')->get();

        $publishments = Publishment::facebookPosts();

        foreach ($messages as $key => $message) {
            $collection->push($message);
        }

        foreach ($publishments as $key => $publishment) {
            $collection->push($publishment);
        }

        return $collection;
    }

    /**
     * Fetch inner comments of facebook
     * @param  datetime $newest
     * @return void
     */
    private function fetchInnerComments($newest)
    {
        $messages = $this->getComments();

        foreach ($messages as $key => $message) {
            $comments = $this->facebookContent->fetchInnerComments($newest, $message['fb_post_id']);

            if($comments == null) {
                continue;
            }

            foreach ($comments->data as $key => $comment) {
                if ($comment->from->id != config('crm-launcher.facebook_credentials.facebook_page_id')
                    && new Datetime(changeFbDateFormat($comment->created_time)) > new Datetime($newest)
                ) {
                    if (! Contact::FindByFbId($comment->from->id)->exists()) {
                        $contact = $this->contact->createContact('facebook', $comment);
                    } else {
                        $contact = Contact::where('facebook_id', $comment->from->id)->first();
                    }

                    $innerComment = new InnerComment();
                    $innerComment->fb_reply_id = $message['fb_post_id'];
                    $innerComment->post_date = changeFbDateFormat($comment->created_time);
                    $innerComment->contact_id = $contact->id;

                    if (is_a($message, "Rubenwouters\CrmLauncher\Models\Answer")) {
                        $innerComment->answer_id = $message['id'];
                    } else if(is_a($message, "Rubenwouters\CrmLauncher\Models\Reaction")){
                        $innerComment->reaction_id = $message['id'];
                    } else {
                        $innerComment->message_id = $message['id'];
                    }

                    $innerComment->fb_post_id = $comment->id;
                    $innerComment->message = $comment->message;
                    $innerComment->save();

                    Media::handleMedia($innerComment->id, $comment, 'facebook_innerComment');
                }
            }
        }
    }

    private function getComments()
    {
        $collection = collect();
        $messages = Message::where('fb_post_id', '!=', '')->get();
        $answers = Answer::where('fb_post_id', '!=', '')->get();
        $reactions = Reaction::where('fb_post_id', '!=', '')->get();

        foreach ($messages as $key => $message) {
            $collection->push($message);
        }

        foreach ($answers as $key => $answer) {
            $collection->push($answer);
        }

        foreach ($reactions as $key => $reaction) {
            $collection->push($reaction);
        }

        return $collection;
    }
}

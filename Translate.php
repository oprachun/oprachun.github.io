<?
require_once('/Common/DB.php');

class CTranslate
{
  private $FProviderID;
  private $FPhraseIDSuffix;
  private $FLangs = ['en', 'uk', 'ru'];
  private $FLangToID = [];

  function __construct($AProviderName)
  {
    $this->FProviderID = fssConGet()->RowGet('SELECT id FROM tsl.providers WHERE name = $1', [$AProviderName])->id;
    $this->FPhraseIDSuffix = '_' . strtolower($AProviderName[0]);
  }

  static function SingletonByIDGet($AProviderID)
  {
    switch(+$AProviderID){
    case 1:
      return CTranslateYandex::SingletonGet();
    case 2:
      return CTranslateBing::SingletonGet();
    case 3:
      return CTranslateGoogle::SingletonGet();
    default:
      Header404($AProviderID);
    }
  }

  protected function LangDetectInternal($APhrase){}

  private function LangDetectBatch()
  {
    $LRows = fssConGet()->RowsGetCheck('
      SELECT P.phrase_id, P.phrase
      FROM tmp_phrases P
      WHERE P.lang_id_detected IS NULL', []
    );
    if(!$LRows)
      return;
    $LValues = [];
    for($i = 0; $i < count($LRows); $i++)
    {
      $LLangID = $this->LangIDGet($this->LangDetectInternal($LRows[$i]->phrase));
      $LValues[] = '(' . $LRows[$i]->phrase_id . ', ' . $LLangID . ')';
    }
    fssConGet()->SQLRun('
      INSERT INTO tsl.lang_detects(provider_id, phrase_id, lang_id)
      SELECT $1, T.phrase_id, T.lang_id
      FROM (VALUES
      ' .
      implode(",\n", $LValues) . '
      ) T (phrase_id, lang_id)
        /*!!LEFT JOIN tsl.lang_detects LD
          ON LD.provider_id = $1
            AND LD.phrase_id = T.phrase_id
      WHERE LD.phrase_id IS NULL*/', [$this->FProviderID]
    );
    fssConGet()->SQLRun('
      UPDATE tmp_phrases P
      SET
        lang_id_detected = Coalesce(LD.lang_id_overridden, LD.lang_id),
        lang_code = L.code
      FROM tsl.lang_detects LD
        INNER JOIN tsl.langs L
          ON L.id = Coalesce(LD.lang_id_overridden, LD.lang_id)
      WHERE LD.provider_id = $1
        AND P.phrase_id = LD.phrase_id
        AND P.lang_id_detected IS DISTINCT FROM Coalesce(LD.lang_id_overridden, LD.lang_id)', [$this->FProviderID]
    );
  }

  function LangDetectOverride($APhraseID, $ALangIDOverr)
  {
    if(!$APhraseID || !$ALangIDOverr)
      Header404('Phrase or LangIDOverr is empty');
    $LSQL = '
      UPDATE tsl.lang_detects
      SET lang_id_overridden = NullIf($3, lang_id)
      WHERE provider_id = $1
        AND phrase_id = $2';
    $this->Override($APhraseID, $LSQL, [$this->FProviderID, $APhraseID, $ALangIDOverr]);
  }

  function LangIDGet($ALang)
  {
    if(count($this->FLangToID) === 0)
    {
      $LRows = fssConGet()->RowsGetCheck('SELECT id, code FROM tsl.langs', []);
      if($LRows)
        for($i = 0; $i < count($LRows); $i++)
          $this->FLangToID[$LRows[$i]->code] = +$LRows[$i]->id;
    }
    $LID = $this->FLangToID[$ALang];
    if(!$LID)
    {
      $LID = +fssConGet()->RowGet('SELECT tsl.fn_lang_id_get($1) lang_id', [$ALang])->lang_id;
      $this->FLangToID[$ALang] = $LID;
    }
    return $LID;
  }

  function Override($APhraseID, $ASQL, $AParams)
  {
    fssConGet()->TranBegin();
    try {
      fssConGet()->SQLRun($ASQL, $AParams);
      fssConGet()->SQLRun('
        UPDATE tsl.phrases_ml
        SET
          phrase_id_en' . $this->FPhraseIDSuffix . ' = NULL,
          phrase_id_uk' . $this->FPhraseIDSuffix . ' = NULL,
          phrase_id_ru' . $this->FPhraseIDSuffix . ' = NULL
        WHERE phrase_id = $1;',
        [$APhraseID]
      );
      $this->Run([$APhraseID]);
    } catch(Exception $E) {
      fssConGet()->TranRollback();
      throw $E;
    } finally {
      fssConGet()->TranCommit();
    }
  }

  static function PhraseMLAdd($APhrase, $ALangCodesBitMask)
  {
    return fssConGet()->RowGet('SELECT tsl.fn_phrase_ml_id_get($1, $2) phrase_id',
      [$APhrase, $ALangCodesBitMask])->phrase_id;
  }

  function Run($APhraseIDs = NULL)
  {
    if($APhraseIDs && count($APhraseIDs))
      $LFilter = 'P.phrase_id IN (' . implode(',', $APhraseIDs) . ')';
    else
      $LFilter = 'P.date_last_request' . $this->FPhraseIDSuffix . ' IS NOT NULL';
    $LSQL = '
      do $$
      declare
        lprovider_id char(1) = \'' . $this->FProviderID . '\';
      begin
        DROP TABLE IF EXISTS tmp_phrases;
        CREATE TEMP TABLE tmp_phrases(
          phrase_id int NOT NULL,
          phrase text NOT NULL,
          lang_id_detected int,
          lang_code varchar,
          phrase_id_spell_checked int,
          phrase_spell_checked text,
          phrase_id_en int,
          phrase_en text,
          phrase_id_uk int,
          phrase_uk text,
          phrase_id_ru int,
          phrase_ru text,
          lang_codes_to_translate varchar,
          lang_ids_to_translate varchar
        );
        INSERT INTO tmp_phrases(
          phrase_id, phrase,
          lang_id_detected, lang_code,
          phrase_id_spell_checked, phrase_spell_checked,
          phrase_id_en, phrase_id_uk, phrase_id_ru,
          lang_codes_to_translate, lang_ids_to_translate)
        SELECT
          P.phrase_id,
          V_P.phrase,
          Coalesce(LD.lang_id_overridden, LD.lang_id),
          L.code,
          Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed),
          V_SC.phrase,
          Coalesce(P.phrase_id_en' . $this->FPhraseIDSuffix . ',
            ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
              FROM tsl.translates _T
              WHERE _T.provider_id = lprovider_id
                AND _T.lang_id_from = Coalesce(LD.lang_id_overridden, LD.lang_id)
                AND _T.lang_id_to = 1/*en*/
                AND _T.phrase_id_from = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)
            ),
            case when Coalesce(LD.lang_id_overridden, LD.lang_id) = 1/*en*/ then Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed) else NULL end
          ) phrase_id_en,
          Coalesce(P.phrase_id_uk' . $this->FPhraseIDSuffix . ',
            ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
              FROM tsl.translates _T
              WHERE _T.provider_id = lprovider_id
                AND _T.lang_id_from = Coalesce(LD.lang_id_overridden, LD.lang_id)
                AND _T.lang_id_to = 2/*uk*/
                AND _T.phrase_id_from = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)
            ),
            case when Coalesce(LD.lang_id_overridden, LD.lang_id) = 2/*uk*/ then Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed) else NULL end
          ) phrase_id_uk,
          Coalesce(P.phrase_id_ru' . $this->FPhraseIDSuffix . ',
            ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
              FROM tsl.translates _T
              WHERE _T.provider_id = lprovider_id
                AND _T.lang_id_from = Coalesce(LD.lang_id_overridden, LD.lang_id)
                AND _T.lang_id_to = 3/*ru*/
                AND _T.phrase_id_from = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)
            ),
            case when Coalesce(LD.lang_id_overridden, LD.lang_id) = 3/*ru*/ then Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed) else NULL end
          ) phrase_id_ru,
          ( SELECT string_agg(_L.code, \',\' ORDER BY _L.id)
            FROM tsl.langs _L
            WHERE _L.bit_code & P.requested_translates <> 0
          ) lang_codes_to_translate,
          ( SELECT string_agg(_L.id::varchar, \',\' ORDER BY _L.id)
            FROM tsl.langs _L
            WHERE _L.bit_code & P.requested_translates <> 0
          ) lang_ids_to_translate
        FROM tsl.phrases_ml P
          INNER JOIN tsl.vw_phrases V_P
            ON V_P.id = P.phrase_id
          LEFT JOIN tsl.lang_detects LD
            ON LD.provider_id = lprovider_id
              AND LD.phrase_id = P.phrase_id
          LEFT JOIN tsl.langs L
            ON L.id = Coalesce(LD.lang_id_overridden, LD.lang_id)
          LEFT JOIN tsl.spell_checks SC
            ON SC.provider_id = lprovider_id
              AND SC.phrase_id = LD.phrase_id
              AND SC.lang_id = Coalesce(LD.lang_id_overridden, LD.lang_id)
          LEFT JOIN tsl.vw_phrases V_SC
            ON V_SC.id = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)
        WHERE ' . $LFilter . '
        ORDER BY P.date_last_request' . $this->FPhraseIDSuffix . ', P.phrase_id
        LIMIT 100;
      end$$';
    fssLog('Started');
    fssConGet()->SQLRun($LSQL, []);
    $LRow = fssConGet()->RowGet('
      SELECT
        (SELECT Count(*) FROM tmp_phrases) count_to_process,
        (SELECT Count(*) FROM tsl.phrases_ml WHERE date_last_request' . $this->FPhraseIDSuffix . ' IS NOT NULL) count_total', []
    );
    $LCount = +$LRow->count_to_process;
    fssLog('Finished init temp table ' . $LCount . ' rows of ' . $LRow->count_total);
    $this->LangDetectBatch();
    fssLog('Finished LangDetectBatch');
    $this->SpellCheckBatch();
    fssLog('Finished SpellCheckBatch');
    $this->TranslateBatch();
    fssLog('Finished TranslateBatch');
    $LSQL = '
      UPDATE tsl.phrases_ml P
      SET
        phrase_id_en' . $this->FPhraseIDSuffix . ' = Coalesce(T.phrase_id_en, P.phrase_id_en' . $this->FPhraseIDSuffix . '),
        phrase_id_uk' . $this->FPhraseIDSuffix . ' = Coalesce(T.phrase_id_uk, P.phrase_id_uk' . $this->FPhraseIDSuffix . '),
        phrase_id_ru' . $this->FPhraseIDSuffix . ' = Coalesce(T.phrase_id_ru, P.phrase_id_ru' . $this->FPhraseIDSuffix . '),
        date_last_request' . $this->FPhraseIDSuffix . ' = NULL
      FROM tmp_phrases T
      WHERE P.phrase_id = T.phrase_id';
    fssConGet()->SQLRun($LSQL, []);
    fssLog('Updated tsl.phrases_ml');
    return ($LCount === 100);
  }

  protected function SpellCheckInternal($ALang, $APhrase){}

  private function SpellCheckBatch()
  {
    $LRows = fssConGet()->RowsGetCheck('
      SELECT P.phrase_id, P.phrase, P.lang_code, P.lang_id_detected
      FROM tmp_phrases P
      WHERE P.phrase_id_spell_checked IS NULL', []
    );
    if(!$LRows)
      return;
    $LValues = [];
    $LParams = [$this->FProviderID];
    for($i = 0; $i < count($LRows); $i++)
    {
      $LParams[] = $this->SpellCheckInternal($LRows[$i]->lang_code, $LRows[$i]->phrase);
      $LValues[] = '(' . $LRows[$i]->phrase_id . ', ' . $LRows[$i]->lang_id_detected . ', $' . count($LParams) . ')';
    }
    fssConGet()->SQLRun('
      INSERT INTO tsl.spell_checks(provider_id, phrase_id, lang_id, phrase_id_processed)
      SELECT $1, T.phrase_id, T.lang_id, tsl.fn_phrase_id_get(T.phrase_spell_checked)
      FROM (VALUES
      ' . implode(",\n", $LValues) . '
      ) T (phrase_id, lang_id, phrase_spell_checked)
        /*!LEFT JOIN tsl.spell_checks SC
          ON SC.provider_id = $1
            AND SC.phrase_id = T.phrase_id
            AND SC.lang_id = T.lang_id
      WHERE SC.phrase_id IS NULL*/', $LParams
    );
    fssConGet()->SQLRun('
      UPDATE tmp_phrases P
      SET
        phrase_id_spell_checked = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed),
        phrase_spell_checked = PH.phrase
      FROM tsl.spell_checks SC
        INNER JOIN tsl.vw_phrases PH
          ON PH.id = Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)
      WHERE SC.provider_id = $1
        AND P.phrase_id = SC.phrase_id
        AND P.lang_id_detected = SC.lang_id
        AND P.phrase_id_spell_checked IS DISTINCT FROM Coalesce(SC.phrase_id_processed_overridden, SC.phrase_id_processed)',
      [$this->FProviderID]
    );
  }

  function SpellCheckOverride($APhraseID, $APhraseOverr)
  {
    if(!$APhraseID || !$APhraseOverr)
      Header404('PhraseID or PhraseOverr is empty');
    $LSQL = '
      UPDATE tsl.spell_checks
      SET phrase_id_processed_overridden = NullIf(tsl.fn_phrase_id_get($3), phrase_id_processed)
      WHERE provider_id = $1
        AND phrase_id = $2
        AND lang_id = (
          SELECT Coalesce(lang_id_overridden, lang_id)
          FROM tsl.lang_detects
          WHERE provider_id = $1
            AND phrase_id = $2
        )';
    $this->Override($APhraseID, $LSQL, [$this->FProviderID, $APhraseID, $APhraseOverr]);
  }

  protected function TranslateInternal($ALangFrom, $ALangTo, $APhraseFrom){}

  private function TranslateBatch()
  {
    $LRows = fssConGet()->RowsGetCheck('
      SELECT DISTINCT
        P.phrase_id_spell_checked,
        P.phrase_spell_checked,
        P.lang_id_detected,
        P.lang_code,
        P.lang_codes_to_translate,
        P.lang_ids_to_translate,
        P.phrase_id_en,
        P.phrase_id_uk,
        P.phrase_id_ru
      FROM tmp_phrases P
      WHERE (P.phrase_id_en IS NULL)
        OR (P.phrase_id_uk IS NULL)
        OR (P.phrase_id_ru IS NULL)', []
    );
    if(!$LRows)
      return;
    $LValues = [];
    $LParams = [$this->FProviderID];
    for($i = 0; $i < count($LRows); $i++)
    {
      $LLangCodes = explode(',', $LRows[$i]->lang_codes_to_translate);
      $LLangIDs = explode(',', $LRows[$i]->lang_ids_to_translate);
      for($iLang = 0; $iLang < count($LLangCodes); $iLang++)
      {
        $LLangIDFrom = +$LRows[$i]->lang_id_detected;
        $LLangTo = $LLangCodes[$iLang];
        $LLangIDTo = +$LLangIDs[$iLang];
        if(!is_null($LRows[$i]->{'phrase_id_' . $LLangTo}))
          continue;
        if($LLangIDFrom === $LLangIDTo)
          continue;
        $LParams[] = $this->TranslateInternal($LRows[$i]->lang_code, $LLangTo, $LRows[$i]->phrase_spell_checked);
        $LValues[] = '  (' .
          $LLangIDFrom . ', ' .
          $LLangIDTo . ', ' .
          $LRows[$i]->phrase_id_spell_checked . ', ' .
          '$' . count($LParams) . ')';
      }
    }
    if(count($LValues) === 0)
      return;
    fssConGet()->SQLRun('
      INSERT INTO tsl.translates(provider_id, lang_id_from, lang_id_to, phrase_id_from, phrase_id_to)
      SELECT $1,
        T.lang_id_from,
        T.lang_id_to,
        T.phrase_id_from,
        tsl.fn_phrase_id_get(T.phrase_to)
      FROM (VALUES
      ' . implode(",\n", $LValues) . '
      ) T (lang_id_from, lang_id_to, phrase_id_from, phrase_to)
        /*LEFT JOIN tsl.translates TR
          ON    TR.provider_id    = $1
            AND TR.lang_id_from   = T.lang_id_from
            AND TR.lang_id_to     = T.lang_id_to
            AND TR.phrase_id_from = T.phrase_id_from
      WHERE TR.provider_id IS NULL*/', $LParams
    );
    fssConGet()->SQLRun('
      UPDATE tmp_phrases P
      SET
        phrase_id_en = Coalesce(P.phrase_id_en,
          ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
            FROM tsl.translates _T
            WHERE _T.provider_id = $1
              AND _T.lang_id_from = P.lang_id_detected
              AND _T.lang_id_to = 1/*en*/
              AND _T.phrase_id_from = P.phrase_id_spell_checked
          )
        ),
        phrase_id_uk = Coalesce(P.phrase_id_uk,
          ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
            FROM tsl.translates _T
            WHERE _T.provider_id = $1
              AND _T.lang_id_from = P.lang_id_detected
              AND _T.lang_id_to = 2/*uk*/
              AND _T.phrase_id_from = P.phrase_id_spell_checked
          )
        ),
        phrase_id_ru = Coalesce(P.phrase_id_ru,
          ( SELECT Coalesce(_T.phrase_id_to_overridden, _T.phrase_id_to)
            FROM tsl.translates _T
            WHERE _T.provider_id = $1
              AND _T.lang_id_from = P.lang_id_detected
              AND _T.lang_id_to = 3/*ru*/
              AND _T.phrase_id_from = P.phrase_id_spell_checked
          )
        )', [$this->FProviderID]
    );
  }

  function TranslateOverride($APhraseID, $ALangIDTo, $APhraseOverr)
  {
    if(!$APhraseID || !$ALangIDTo || !$APhraseOverr)
      Header404('PhraseID or LangIDTo or PhraseOverr is empty');
    $LLangIDFrom = fssConGet()->RowGet('
      SELECT Coalesce(lang_id_overridden, lang_id) lang_id
      FROM tsl.lang_detects
      WHERE provider_id = $1
        AND phrase_id = $2', [$this->FProviderID, $APhraseID])->lang_id;
    $LSQL = '
      UPDATE tsl.translates
      SET phrase_id_to_overridden = NullIf(tsl.fn_phrase_id_get($5), phrase_id_to)
      WHERE provider_id = $1
        AND phrase_id_from = (
          SELECT Coalesce(phrase_id_processed_overridden, phrase_id_processed)
          FROM tsl.spell_checks
          WHERE provider_id = $1
            AND phrase_id = $2
            AND lang_id = $3
        )
        AND lang_id_from = $3
        AND lang_id_to = $4';
    $this->Override($APhraseID, $LSQL, [$this->FProviderID, $APhraseID, $LLangIDFrom, $ALangIDTo, $APhraseOverr]);
  }
}

class CTranslateBing extends CTranslate
{
  private static $Singleton = NULL;

  function __construct()
  {
    parent::__construct('Bing');
  }

  static function SingletonGet()
  {
    if(!self::$Singleton)
      self::$Singleton = new CTranslateBing();
    return self::$Singleton;
  }

  protected function LangDetectInternal($APhrase)
  {
  }

  protected function SpellCheckInternal($ALang, $APhrase)
  {
  }

  protected function TranslateInternal($ALangFrom, $ALangTo, $APhraseFrom)
  {
  }
}

class CTranslateGoogle extends CTranslate
{
  private static $Singleton = NULL;

  function __construct()
  {
    parent::__construct('Google');
  }

  static function SingletonGet()
  {
    if(!self::$Singleton)
      self::$Singleton = new CTranslateGoogle();
    return self::$Singleton;
  }

  protected function LangDetectInternal($APhrase)
  {
    $LLang = $this->TranslateInternal2('auto', '', $APhrase, 'LD')[2];
    fssLog(__METHOD__ . ': ' . '"' . $APhrase . '" -> ' . $LLang);
    return $LLang;
  }

  protected function SpellCheckInternal($ALang, $APhrase)
  {
    $LResponse = $this->TranslateInternal2($ALang, '', $APhrase, 'SC');
    if($LResponse[7])
      $LPhrase = $LResponse[7][1];
    else
      $LPhrase = $APhrase;
    $LMessage = __METHOD__ . ': ' . '"' . $APhrase . '"';
    if($APhrase !== $LPhrase)
      $LMessage = $LMessage . ' -> "' . $LPhrase . '"';
    fssLog($LMessage);
    return $LPhrase;
  }

  protected function TranslateInternal($ALangFrom, $ALangTo, $APhraseFrom)
  {
    if($ALangFrom === $ALangTo)
      throw new Exception('LangFrom is equal to LangTo ' . $ALangFrom);
    $LResponse = $this->TranslateInternal2($ALangFrom, $ALangTo, $APhraseFrom, 'TR');
    $LPhraseTo = $LResponse[0][0][0];
    fssLog(__METHOD__ . ': (' . $ALangFrom . ' -> ' . $ALangTo . '), ' .
      '"' . $APhraseFrom . '" -> "' . $LPhraseTo . '"');
    return $LPhraseTo;
  }

  private function TranslateInternal2($ALangFrom, $ALangTo, $APhraseFrom, $AProcess)
  {
    $LURL = 'https://translate.googleapis.com/translate_a/single' .
      '?client=gtx' .
      '&sl=' . $ALangFrom .
      //'&tl=' . $ALangTo .
      //'&hl=' . $ALangTo .
      //'&dt=at' . //word translate variants
      //'&dt=bd' .
      //'&dt=ex' .
      //'&dt=ld' .
      //'&dt=md' .
      //'&dt=qca' . //spell check
      //'&dt=rm' . //translit
      //'&dt=ss' .
      //'&dt=t' . //translate
      '&ie=UTF-8' .
      '&oe=UTF-8' .
      '&otf=1' .
      '&ssel=0' .
      '&tsel=0' .
      '&kc=7' .
      '&q=' . urlencode($APhraseFrom);
    switch($AProcess){
    case 'LD':
      $LURL = $LURL .
        '&dt=ld';
      break;
    case 'SC':
      $LURL = $LURL .
        '&dt=qca';
      break;
    case 'TR':
      $LURL = $LURL .
        '&tl=' . $ALangTo .
        '&dt=t';
      break;
    default:
      throw new Exception('Google Translate Unknown Process Name: ' . $AProcess);
    }
    $LRequest = curl_init($LURL);
    curl_setopt($LRequest, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($LRequest, CURLOPT_FOLLOWLOCATION, true);
    $LResponse = curl_exec($LRequest);
    $LResponseCode = curl_getinfo($LRequest, CURLINFO_HTTP_CODE);
    if(+$LResponseCode !== 200)
      Header404($LResponseCode . $LResponse);
    curl_close($LRequest);
    /*
      $LResponse[0] - array of translates(translate(0), translit(1))
      $LResponse[1] -
      $LResponse[2] - detected language
      $LResponse[3] -
      $LResponse[4] -
      $LResponse[5] - word translate variants
      $LResponse[6] - ? rating
      $LResponse[7] - spell check info
      $LResponse[8] - array of suggest languages
    */
    return json_decode($LResponse);
  }
}

class CTranslateYandex extends CTranslate
{
  private static $Singleton = NULL;
  private $FKey = 'trnsl.1.1.20160905T104024Z.520fc0aabeedd62c.aab9c1dd6925b6964cf12aa2632eb154615f7275';

  function __construct()
  {
    parent::__construct('Yandex');
  }

  static function SingletonGet()
  {
    if(!self::$Singleton)
      self::$Singleton = new CTranslateYandex();
    return self::$Singleton;
  }

  protected function LangDetectInternal($APhrase)
  {
    $LURL = 'https://translate.yandex.net/api/v1.5/tr.json/detect' .
      '?key=' . $this->FKey .
      '&hint=uk,ru,en' .
      '&text=' . urlencode($APhrase);
    $LLang = json_decode(CUrlGetSimple($LURL))->lang;
    fssLog(__METHOD__ . ': ' . '"' . $APhrase . '" -> ' . $LLang);
    return $LLang;
  }

  protected function SpellCheckInternal($ALang, $APhrase)
  {
    $IGNORE_UPPERCASE      = 1;
    //$IGNORE_DIGITS         = 2;
    //$IGNORE_URLS           = 4;
    //$FIND_REPEAT_WORDS     = 8;
    //$IGNORE_LATIN          = 16;
    //$NO_SUGGEST            = 32;
    //$FLAG_LATIN            = 128;
    //$BY_WORDS              = 256;
    //$IGNORE_CAPITALIZATION = 512;
    //$IGNORE_ROMAN_NUMERALS = 2048;
    $LURL = 'http://speller.yandex.net/services/spellservice.json/checkText' .
      '?lang=' . $ALang .
      '&options=' . ($IGNORE_UPPERCASE) .
      '&text=' . urlencode($APhrase);
    $LPhrase = $this->SpellCheckPrepare(json_decode(CUrlGetSimple($LURL)), $APhrase);
    $LMessage = __METHOD__ . ': ' . '"' . $APhrase . '"';
    if($APhrase !== $LPhrase)
      $LMessage = $LMessage . ' -> "' . $LPhrase . '"';
    fssLog($LMessage);
    return $LPhrase;
  }

  private function SpellCheckPrepare($ARoot, $APhrase)
  {
    $LPhraseTo = $APhrase;
    for($i = count($ARoot) - 1; $i >= 0; $i--)
      if(count($ARoot[$i]->s) > 0)
        $LPhraseTo = mb_substr($LPhraseTo, 0, $ARoot[$i]->pos) .
          $ARoot[$i]->s[0] . mb_substr($LPhraseTo, $ARoot[$i]->pos + $ARoot[$i]->len);
    return $LPhraseTo;
  }

  protected function TranslateInternal($ALangFrom, $ALangTo, $APhraseFrom)
  {
    if($ALangFrom === $ALangTo)
      throw new Exception('LangFrom is equal to LangTo ' . $ALangFrom);
    $LURL = 'https://translate.yandex.net/api/v1.5/tr.json/translate' .
      '?key=' . $this->FKey .
      '&lang=' . $ALangFrom . '-' . $ALangTo .
      '&format=plain' .
      '&options=1' .
      '&text=' . urlencode($APhraseFrom);
    $LPhraseTo = json_decode(CUrlGetSimple($LURL))->text[0];
    fssLog(__METHOD__ . ': (' . $ALangFrom . ' -> ' . $ALangTo . '), ' .
      '"' . $APhraseFrom . '" -> "' . $LPhraseTo . '"');
    return $LPhraseTo;
  }
}

function ActionProcess()
{
  $LAction = ParamValueGet('a', true, 'Action');
  switch($LAction) {
  case 'PhraseEdit':
    $PhraseID = ParamValueGetForce_PositiveInt('phrase_id');
    $PhraseEditContent = PhraseEdit_Build($PhraseID, $Phrase);
    $CategoryNamesLong = PhraseEdit_PhraseDependenciesBuild($PhraseID);
    require_once('/home/fssdomains/BACKEND/Translate/PhraseEdit.php');
    break;
  case 'PhraseList':
    PhraseList_ParamsRead($LPageNo, $LPageCount, $LFilter, $LSQLFilter, $LLimit, $LMerchantID, $TableType);
    $Merchants = PhraseList_MerchantsLoad($MerchantsDisabled, $LMerchantID, $TableType);
    $PageNavigator = PhraseList_PNBuild($LFilter, $LPageNo, $LPageCount);
    $PhraseList = PhraseList_Build($PhraseIDsS, $LPageNo, $LLimit, $LSQLFilter);
    require_once('/home/fssdomains/BACKEND/Translate/PhrasesList.php');
    break;
  case 'Translate':
    $LPhraseIDs = ParamValueGet('phrase_ids', false, 'PhraseIDs');
    if($LPhraseIDs)
      $LPhraseIDs = explode(',', $LPhraseIDs);
    else
      $LPhraseIDs = NULL;
    CTranslateYandex::SingletonGet()->Run($LPhraseIDs);
    CTranslateGoogle::SingletonGet()->Run($LPhraseIDs);
    echo '<pre>' . fssLogsGet() . '</pre>';
    break;
  case 'CategoriesNamesTranslates':
    $LMerchantID = ParamValueGetForce_PositiveInt('m', NULL, 3);
    CategoriesNamesTranslates($LMerchantID);
    echo fssLogsGet();
    break;
  case 'SaveOverrides':
    $LPhraseID = ParamValueGetForce_PositiveInt('PhraseID');
    foreach($_GET as $LKey => $LValue)
    {
      if(in_array($LKey, ['a', 'PhraseID']))
        continue;
      $LSuffix = substr($LKey, -7);
      fssLog($LKey . ' ' . $LValue);
      switch($LSuffix){
      case '_y_over':
        $LTranslate = CTranslateYandex::SingletonGet();
        break;
      case '_b_over':
        $LTranslate = CTranslateBing::SingletonGet();
        break;
      case '_g_over':
        $LTranslate = CTranslateGoogle::SingletonGet();
        break;
      default:
        Header404($LSuffix);
      }
      $LValue = ParamValueGet($LKey, true);
      $LOverride = substr($LKey, 0, 2);
      switch($LOverride){
      case 'ld':
        $LLangID = +$LValue;
        if(!in_array($LLangID, [1, 2, 3]))
          Header404('Not supported lang_id ' . $LLangID);
        $LTranslate->LangDetectOverride($LPhraseID, $LLangID);
        break;
      case 'sc':
        $LTranslate->SpellCheckOverride($LPhraseID, $LValue);
        break;
      case 'tr':
        $LLang = substr($LKey, 10, 2);
        $LLangs = ['en', 'uk', 'ru'];
        if(!in_array($LLang, $LLangs))
          Header404('Not supported lang ' . $LLang);
        $LLangID = array_search($LLang, $LLangs) + 1;
        fssLog($LLangID);
        $LTranslate->TranslateOverride($LPhraseID, $LLangID, $LValue);
        break;
      default:
        Header404('Unknown param ' . $LKey);
      }
    }
    echo fssLogsGet();
    break;
  default:
    Header404($LAction);
  }
}

function CategoriesNamesTranslates($AMerchantID)
{
  $LSQL = '
    UPDATE pp.categories C
    SET
      name_phrase_ml_id = tsl.fn_phrase_ml_id_get(C.name_, 7)
    WHERE C.merchant_id = $1
      AND C.name_phrase_ml_id IS NULL
      AND NOT EXISTS(SELECT * FROM pp.brands _B WHERE _B.merchant_id = C.merchant_id AND _B.name = C.name_)';
  fssConGet()->SQLRun($LSQL, [$AMerchantID]);
  fssLog('Update ' . fssConGet()->LastAffectedRows . ' categories');
}

function HTML_SelectHEBuild($AName, $AValues, $AValueSelected, $AValueBase)
{
  $LResult = '<select name="' . $AName . '" valueInit="' . $AValueSelected . '" valueBase="' . $AValueBase . '">';
  for($i = 0; $i < count($AValues); $i+=2)
  {
    $LSelected = ($AValueSelected === $AValues[$i] ? ' selected' : '');
    $LResult .= '<option value="' . $AValues[$i] . '"' . $LSelected . '>' . $AValues[$i + 1] . '</option>';
  }
  $LResult .= '</select>';
  return $LResult;
}

function PhraseEdit_Build($APhraseID, &$APhrase)
{
  $LSQL = "
    SELECT
      phrase_id,
      phrase,
      (SELECT phrase_id FROM tsl.phrases_ml WHERE phrase_id = tr_phrase_id_en) tr_phrase_id_en,
      tr_phrase_en,
      (SELECT phrase_id FROM tsl.phrases_ml WHERE phrase_id = tr_phrase_id_uk) tr_phrase_id_uk,
      tr_phrase_uk,
      (SELECT phrase_id FROM tsl.phrases_ml WHERE phrase_id = tr_phrase_id_ru) tr_phrase_id_ru,
      tr_phrase_ru,

      ld_lang_y,
      ld_lang_id_y,
      ld_lang_y_over,
      ld_lang_id_y_over,
      sc_phrase_y,
      sc_phrase_y_over,
      tr_phrase_en_y,
      tr_phrase_en_y_over,
      tr_phrase_uk_y,
      tr_phrase_uk_y_over,
      tr_phrase_ru_y,
      tr_phrase_ru_y_over,

      ld_lang_g,
      ld_lang_id_g,
      ld_lang_g_over,
      ld_lang_id_g_over,
      sc_phrase_g,
      sc_phrase_g_over,
      tr_phrase_en_g,
      tr_phrase_en_g_over,
      tr_phrase_uk_g,
      tr_phrase_uk_g_over,
      tr_phrase_ru_g,
      tr_phrase_ru_g_over
    FROM tsl.vw_phrases_ml_over
    WHERE phrase_id = $1";
  //DW($LSQL);
  $LRow = fssConGet()->RowGet($LSQL, [$APhraseID]);
  $APhrase = $LRow->phrase;

  $LLangs = array(
    '', '',
    '1', 'English',
    '2', 'Ukrainian',
    '3', 'Russian'
  );
  $LEqualS = [false => '', true => ' class="not-equal"'];
  return
"      <tr>
        <th></th>
        <th>Result</th>
        <th>Yandex</th>
        <th>Google</th>
      </tr>
      <tr>
        <th>Lang Detected</th>
        <td></td>
        <td" . $LEqualS[($LRow->ld_lang_y_over ?: $LRow->ld_lang_y) !== ($LRow->ld_lang_g_over ?: $LRow->ld_lang_g)] . ">$LRow->ld_lang_y</td>
        <td" . $LEqualS[($LRow->ld_lang_y_over ?: $LRow->ld_lang_y) !== ($LRow->ld_lang_g_over ?: $LRow->ld_lang_g)] . ">$LRow->ld_lang_g</td>
      </tr>
      <tr>
        <th>Lang Detected over</th>
        <td></td>
        <td>" . HTML_SelectHEBuild('ld_lang_y_over', $LLangs, $LRow->ld_lang_id_y_over, $LRow->ld_lang_id_y) . "</td>
        <td>" . HTML_SelectHEBuild('ld_lang_g_over', $LLangs, $LRow->ld_lang_id_g_over, $LRow->ld_lang_id_g) . "</td>
      </tr>
" .
    PhraseEdit_BuildSpellCheck($LRow) .
    PhraseEdit_BuildTranslate($LRow, 'en') .
    PhraseEdit_BuildTranslate($LRow, 'uk') .
    PhraseEdit_BuildTranslate($LRow, 'ru');
}

function PhraseEdit_BuildSpellCheck($ARow)
{
  $LPhraseY  = $ARow->sc_phrase_y;
  $LPhraseYO = $ARow->sc_phrase_y_over;
  $LPhraseG  = $ARow->sc_phrase_g;
  $LPhraseGO = $ARow->sc_phrase_g_over;
  if(($LPhraseYO ?: $LPhraseY) !== ($LPhraseGO ?: $LPhraseG))
    $LEqualS = ' class="not-equal"';
  return
"      <tr>
        <th>Spell Check</th>
        <td></td>
        <td$LEqualS>$LPhraseY</td>
        <td$LEqualS>$LPhraseG</td>
      </tr>
      <tr>
        <th>Spell Check over</th>
        <td></td>
        <td><input type=\"text\" name=\"sc_phrase_y_over\" value=\"$LPhraseYO\" valueInit=\"$LPhraseYO\" valueBase=\"$LPhraseY\"></td>
        <td><input type=\"text\" name=\"sc_phrase_g_over\" value=\"$LPhraseGO\" valueInit=\"$LPhraseYO\" valueBase=\"$LPhraseG\"></td>
      </tr>
";
}

function PhraseEdit_BuildTranslate($ARow, $ALang)
{
  $LPhraseID = $ARow->{'tr_phrase_id_' . $ALang};
  $LPhrase   = htmlspecialchars($ARow->{'tr_phrase_' . $ALang});
  $LLangY    = $ARow->ld_lang_y_over ?: $ARow->ld_lang_y;
  $LPhraseY  = htmlspecialchars($ARow->{'tr_phrase_' . $ALang . '_y'});
  $LPhraseYO = htmlspecialchars($ARow->{'tr_phrase_' . $ALang . '_y_over'});
  $LLangG    = $ARow->ld_lang_g_over ?: $ARow->ld_lang_g;
  $LPhraseG  = htmlspecialchars($ARow->{'tr_phrase_' . $ALang . '_g'});
  $LPhraseGO = htmlspecialchars($ARow->{'tr_phrase_' . $ALang . '_g_over'});
  if(($LPhraseYO ?: $LPhraseY) !== ($LPhraseGO ?: $LPhraseG))
    $LEqualS = ' class="not-equal"';
  if($LPhraseID && ($LPhraseID !== $ARow->phrase_id))
    $LLink = "<a href=\"?a=PhraseEdit&phrase_id=$LPhraseID\">$LPhrase</a>";
  else
    $LLink = $LPhrase;
  $LTDYO = $LLangY === $ALang ? '' : '<input type="text" name="tr_phrase_' . $ALang . '_y_over" value="' . $LPhraseYO . '" valueInit="' . $LPhraseYO . '" valueBase="' . $LPhraseY . '">';
  $LTDGO = $LLangG === $ALang ? '' : '<input type="text" name="tr_phrase_' . $ALang . '_g_over" value="' . $LPhraseGO . '" valueInit="' . $LPhraseGO . '" valueBase="' . $LPhraseG . '">';
  return
"      <tr>
        <th>Translate $ALang</th>
        <td>$LLink</td>
        <td$LEqualS>$LPhraseY</td>
        <td$LEqualS>$LPhraseG</td>
      </tr>
      <tr>
        <th>Translate $ALang over</th>
        <td></td>
        <td>$LTDYO</td>
        <td>$LTDGO</td>
      </tr>
";
}

function PhraseEdit_PhraseDependenciesBuild($APhraseID)
{
  $LSQL = "
    SELECT 'Product Categories' block_name, name_long,
      (SELECT name FROM bo.bo_merchants WHERE id = merchant_id) merchant_name,
      c_product_count_avaliable_recursive prod_count,
      category_id
    FROM pp.categories
    WHERE name_phrase_ml_id = $1
    UNION ALL
    SELECT 'Merchant Categories', name_long, NULL, NULL, NULL
    FROM bo.bo_merchant_categories
    WHERE name_phrase_ml_id = $1
    UNION ALL
    SELECT 'Coupon Categories', name, NULL, NULL, NULL
    FROM cpn.categories
    WHERE name_phrase_ml_id = $1
    UNION ALL
    SELECT 'Coupon Names', name, NULL, NULL, NULL
    FROM cpn.coupons
    WHERE name_phrase_ml_id = $1
    UNION ALL
    SELECT 'Product Attribute Names', name, (SELECT name FROM bo.bo_merchants WHERE id = merchant_id) merchant_name, NULL, NULL
    FROM pp.product_attributes
    WHERE name_phrase_ml_id = $1
    ORDER BY block_name, merchant_name, name_long
    ";
  $LRows = fssConGet()->RowsGetCheck($LSQL, [$APhraseID]);
  if(!$LRows)
    return;
  $LTables = [];
  $LBlockName = '';
  for($i = 0; $i < count($LRows); $i++)
  {
    $LRow = $LRows[$i];
    if($LBlockName !== $LRow->block_name)
    {
      if($LTRs)
        $LTables[] = '  <table>' . "\n" . implode("\n", $LTRs) . "\n" . '  </table>' . "\n";
      $LBlockName = $LRow->block_name;
      if($LBlockName === 'Product Categories')
        $LTRs = [
          '    <tr><th colspan="4" class="block-name">' . $LBlockName . '</th></tr>',
          '    <tr><th>Merchant</th><th>Category name long</th><th>Product count</th><th>Category ID</th></tr>'
        ];
      else
      if($LBlockName === 'Product Attribute Names')
        $LTRs = [
          '    <tr><th colspan="2" class="block-name">' . $LBlockName . '</th></tr>',
          '    <tr><th>Merchant</th><th>Attribute name</th></tr>'
        ];
      else
        $LTRs = [
          '    <tr><th class="block-name">' . $LBlockName . '</th></tr>',
          '    <tr><th>Name</th></tr>'
        ];
    }
    if($LBlockName === 'Product Categories')
    {
      $LURL = '/store/?uk-uah&store=' . $LRow->merchant_name;
      $LURLCat = $LURL . '&category=' . urlencode(mb_strtolower($LRow->name_long));
      $LTRs[] = '    <tr><td><a href="' . $LURL . '">' . $LRow->merchant_name . '</a></td>' .
        '<td><a href="' . $LURLCat . '">' . $LRow->name_long . '</a></td>' .
        '<td class="align-right">' . $LRow->prod_count . '</td>' .
        '<td class="align-right">' . $LRow->category_id . '</td></tr>';
    }
    else
    if($LBlockName === 'Product Attribute Names')
    {
      $LURL = '/store/?uk-uah&store=' . $LRow->merchant_name;
      $LTRs[] = '    <tr><td><a href="' . $LURL . '">' . $LRow->merchant_name . '</a></td>' .
        '<td>' . $LRow->name_long . '</td></tr>';
    }
    else
      $LTRs[] = '    <tr><td>' . $LRow->name_long . '</td></tr>';
  }
  $LTables[] = '  <table>' . "\n" . implode("\n", $LTRs) . "\n" . '  </table>' . "\n";
  return implode('', $LTables);
}

function PhraseList_MerchantsLoad(&$AMerchantsDisabled, $AMerchantID, $ATableType)
{
  $LSQL = '
    SELECT M.id merchant_id, M.name merchant_name,
      (SELECT Count(distinct C.name_phrase_ml_id) FROM pp.categories C WHERE C.merchant_id = M.id) name_count,
      EXISTS(SELECT * FROM pp.categories C WHERE C.merchant_id = M.id AND C.name_phrase_ml_id IS NOT NULL) name_ml_exists
    FROM bo.bo_merchants M
    WHERE EXISTS (SELECT * FROM pp.categories C WHERE C.merchant_id = M.id)
    ORDER BY name_ml_exists DESC, M.name, M.id';
  $LRows = fssConGet()->RowsGet($LSQL, []);
  $LOptions = ['      <option value="0">Select merchant</option>' . "\n"];
  for($i = 0; $i < count($LRows); $i++)
    $LOptions[] = '      <option ' .
      'value="' . $LRows[$i]->merchant_id . '"' .
      ($AMerchantID === $LRows[$i]->merchant_id ? ' selected="true"' : '') . '>' .
      $LRows[$i]->merchant_name . '(' . $LRows[$i]->name_count . ')' .
      '</option>' . "\n";
  $AMerchantsDisabled = (in_array($ATableType, ['pp-cat-n', 'pp-atr-n']) ? '' : ' disabled="true"');
  return "\n" . implode('', $LOptions);
}

function PhraseList_ParamsRead(&$APageNo, &$APageCount, &$AFilter, &$ASQLFilter, &$ALimit, &$AMerchantID, &$ATableType)
{
  $ALimit = $_GET['c'];
  if(isset($ALimit))
    $ALimit = +$ALimit;
  else
    $ALimit = 20;
  $APageNo = $_GET['p'];
  if(isset($APageNo))
    $APageNo = +$APageNo;
  else
    $APageNo = 1;
  $ATableType = $_GET['t'];
  $AFilter = '?a=PhraseList' . ($ALimit === 20 ? '' : '&c=' . $ALimit);
  if($ATableType)
  {
    $AFilter .= '&t=' . $ATableType;
    if(in_array($ATableType, ['pp-cat-n', 'pp-atr-n']))
    {
      $AMerchantID = $_GET['m'];
      $AFilter .= '&m=' . $AMerchantID;
    }
    $LFieldName = 'name_phrase_ml_id';
    switch($ATableType){
    case 'cpn-cat-n':
      $LTableName = 'cpn.categories';
      break;
    case 'cpn-cpn-n':
      $LTableName = 'cpn.coupons';
      break;
    case 'pp-atr-n':
      $LTableName = 'pp.product_attributes';
      $ASQLFilter = '
        WHERE merchant_id = ' . $AMerchantID;
      break;
    case 'pp-cat-n':
      $LTableName = 'pp.categories';
      $ASQLFilter = '
        WHERE merchant_id = ' . $AMerchantID;
      break;
    case 'm-cat-n':
      $LTableName = 'bo.bo_merchant_categories';
      break;
    }

    $ASQLFilter = '
      WHERE phrase_id IN (
        SELECT ' . $LFieldName . '
        FROM ' . $LTableName . $ASQLFilter . '
      )';
  }
  $LSQL = 'SELECT ceil(Count(*)::numeric / $1) c FROM tsl.vw_phrases_ml' . $ASQLFilter;
  //DW($LSQL);
  $APageCount = +fssConGet()->RowGet($LSQL, [$ALimit])->c;
  if($APageNo < 1)
  {
    header('Location: ' . $_SERVER['SCRIPT_URI'] . $AFilter);
    exit;
  }
  else
  if(($APageNo > $APageCount) && ($APageNo > 1))
  {
    header('Location: ' . $_SERVER['SCRIPT_URI'] . $AFilter . '&p=' . $APageCount);
    exit;
  }
}

function PhraseList_PNBuild($AFilter, $APageNo, $APageCount)
{
  $LResult = '  <a href="' . $AFilter . '">First</a>' . "\n";
  if($APageNo > 1)
    $LResult .= '  <a href="' . $AFilter . '&p=' . ($APageNo - 1) . '">Prev</a>' . "\n";
  if($APageNo < $APageCount)
    $LResult .= '  <a href="' . $AFilter . '&p=' . ($APageNo + 1) . '">Next</a>' . "\n";
  $LResult .= '  <a href="' . $AFilter . '&p=' . $APageCount . '">Last</a>' . "\n";
  return $LResult;
}

function PhraseList_RowBuild($ARow, $AKeys)
{
  $LValues = [];
  for($i = 0; $i < count($AKeys); $i++)
    if($ARow)
    {
      if($AKeys[$i][0] === ' ')
        $LValues[] = '<td class="td-delimiter"></td>';
      else
      if($AKeys[$i] === 'phrase_id')
        $LValues[] = '<td class="align-right"><a href="?a=PhraseEdit&phrase_id=' . $ARow->phrase_id . '">' . $ARow->phrase_id . '</a></td>';
      else
      {
        $LClass = '';
        if(in_array(substr($AKeys[$i], -2), ['_y', '_g']))
        {
          $LNamePrefix = substr($AKeys[$i], 0, -2);
          if($ARow->{$LNamePrefix . '_y'} !== $ARow->{$LNamePrefix . '_g'})
            $LClass = ' class="not-equal"';
        }
        else
        if(is_null($ARow->$AKeys[$i]))
          $LClass = ' class="not-equal"';
        $LValues[] = '<td' . $LClass . '>' . $ARow->$AKeys[$i] . '</td>';
      }
    }
    else
      if($AKeys[$i][0] === ' ')
        $LValues[] = '<th class="td-delimiter"></th>';
      else
        $LValues[] = '<th>' . $AKeys[$i] . '</th>';
  return '    <tr>' . implode($LValues) . '</tr>' . "\n";
}

function PhraseList_Build(&$APhraseIDsS, $APageNo, $ALimit, $ASQLFilter)
{
  $LSQL = '
    SELECT
      NULL " 1",
      P.phrase_id,
      P.phrase,
      NULL " 2",
      P.phrase_en,
      P.phrase_uk,
      P.phrase_ru,
      NULL " 3",
      ( SELECT L.code
        FROM tsl.lang_detects LD
          INNER JOIN tsl.langs L
            ON L.id = Coalesce(LD.lang_id_overridden, LD.lang_id)
        WHERE LD.provider_id = \'1\'
          AND LD.phrase_id = P.phrase_id
      ) lang_y,
      P.phrase_en_y,
      P.phrase_uk_y,
      P.phrase_ru_y,
      NULL " 4",
      ( SELECT L.code
        FROM tsl.lang_detects LD
          INNER JOIN tsl.langs L
            ON L.id = Coalesce(LD.lang_id_overridden, LD.lang_id)
        WHERE LD.provider_id = \'3\'
          AND LD.phrase_id = P.phrase_id
      ) lang_g,
      P.phrase_en_g,
      P.phrase_uk_g,
      P.phrase_ru_g,
      NULL " 5"
    FROM tsl.vw_phrases_ml P' . $ASQLFilter . '
    ORDER BY P.phrase_id
    LIMIT $1 OFFSET $2';
  //DW($LSQL);
  $LRows = fssConGet()->RowsGetCheck($LSQL, [$ALimit, $ALimit * ($APageNo - 1)]);
  if(!$LRows)
    return;
  $LKeys = array_keys((array)$LRows[0]);
  $LResult = "\n" . PhraseList_RowBuild(null, $LKeys);
  $LPhraseIDs = [];
  for($i = 0; $i < count($LRows); $i++)
  {
    $LResult .= PhraseList_RowBuild($LRows[$i], $LKeys);
    $LPhraseIDs[] = $LRows[$i]->phrase_id;
  }
  $APhraseIDsS = implode(',', $LPhraseIDs);
  return $LResult;
}
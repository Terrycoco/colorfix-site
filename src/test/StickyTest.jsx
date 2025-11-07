import StickyToolbar from '@layout/StickyToolbar';

export default function StickyTest() {
  return (
    <div>
      <StickyToolbar>
        i am a stickytoolbar
      </StickyToolbar>
     <div style={{ height: '200vh', paddingTop: '2rem' }}>
        Scroll me!
      </div>
   </div>
  );
}

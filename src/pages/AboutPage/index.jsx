import ColorWheel from '@components/ColorWheel';
import {useAppState} from '@context/appStateContext';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import ColorWheel400 from '@components/ColorWheel/ColorWheel400';
import ColorOnly300 from '@components/ColorWheel/ColorOnly300';
import Column from '@layout/Column';

function AboutPage() {
  const {currentColor} = useAppState();

  return (
    <Column align="center">

    <ColorWheel width='250'  currentColor={null}/>


    </Column>
  );
}

export default AboutPage;
  //  <ColorWheel width='300'  currentColor={currentColor}/>
 //     <ColorWheel400 currentColor={currentColor}/>

 

  //    <ColorWheel400 currentColor={currentColor} />
   //    <ColorWheel width={300} height={300} currentColor={currentColor} />